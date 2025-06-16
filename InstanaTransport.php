<?php

declare(strict_types=1);

namespace Instana;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;

use Psr\Http\Message\ResponseInterface;

use BadMethodCallException;
use Exception;
use RuntimeException;

class InstanaTransport implements TransportInterface
{
    use LogsMessagesTrait;

    const CONTENT_TYPE = 'application/json';
    const ATTEMPTS = 3;

    private Client $client;
    private ?string $agent_uuid = null;
    private ?int $pid = null;
    private array $secrets = [];
    private array $tracing = [];

    private bool $closed = true;
    private array $headers = [];

    public function __construct(
        private readonly string $endpoint,
        private readonly float $timeout = 0.0
    ) {
        $this->headers += ['Content-Type' => self::CONTENT_TYPE];
        if ($timeout > 0.0) {
            $this->headers += ['timeout' => $timeout];
        }

        $this->client = new Client(['base_uri' => $endpoint]);

        $this->announce();
    }

    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->closed) {
            return new ErrorFuture(new BadMethodCallException('Transport closed'));
        }

        $response = $this->sendPayload($payload);

        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300) {
            self::logDebug("Sending failed with code " . $code);
            try {
                $this->announce();
            } catch (Exception $e) {
                return new ErrorFuture($e);
            }
        }

        return new CompletedFuture('Payload successfully sent');
    }

    private function sendPayload(string $payload): ResponseInterface
    {
        return $this->client->sendRequest(
            new Request(
                method: 'POST',
                uri: new Uri('/com.instana.plugin.php/traces.' . $this->pid),
                headers: $this->headers,
                body: $payload
            )
        );
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->closed = true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return !$this->closed;
    }

    private function announce()
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS && !$this->performAnnounce(); $attempt++) {
            self::logDebug("Discovery request failed, attempt " . $attempt);
            sleep(5);
        }

        if (is_null($this->agent_uuid) || is_null($this->pid)) {
            throw new Exception('Failed announcement in transport. Missing pid or uuid from agent');
        }
    }

    private function performAnnounce(): bool
    {
        self::logDebug("Announcing to " . $this->endpoint);

        // Phase 1) Host lookup.
        $response = $this->client->sendRequest(
            new Request(method: 'GET', uri: new Uri('/'), headers: $this->headers)
        );

        $code = $response->getStatusCode();
        $msg = $response->getBody()->getContents();

        if ($code != 200 && !array_key_exists('version', json_decode($msg, true))) {
            self::LogError("Failed to lookup host. Received code " . $code . " with message: " . $msg);
            $this->closed = true;
            return false;
        }

        self::logDebug("Phase 1 announcement response code " . $code);

        // Phase 2) Announcement.
        $response = $this->client->sendRequest(
            new Request(
                method: 'PUT',
                uri: new Uri('/com.instana.plugin.php.discovery'),
                headers: $this->headers,
                body: $this->getAnnouncementPayload()
            )
        );

        $code = $response->getStatusCode();
        $msg = $response->getBody()->getContents();

        self::logDebug("Phase 2 announcement response code " . $code);

        if ($code < 200 || $code >= 300) {
            self::LogError("Failed announcement. Received code " . $code . " with message: " . $msg);
            $this->closed = true;
            return false;
        }

        $content = json_decode($msg, true);
        if (!array_key_exists('pid', $content)) {
            self::LogError("Failed to receive a pid from agent");
            $this->closed = true;
            return false;
        }

        $this->pid = $content['pid'];
        $this->agent_uuid = $content['agentUuid'];

        // Optional values that we may receive from the agent.
        if (array_key_exists('secrets', $content))
            $this->secrets = $content['secrets'];
        if (array_key_exists('tracing', $content))
            $this->tracing = $content['tracing'];

        // Phase 3) Wait for the agent ready signal.
        for ($retry = 0; $retry < 5; $retry++) {
            if ($retry)
                self::logDebug("Agent not yet ready, attempt " . $retry);

            $response = $this->client->sendRequest(
                new Request(
                    method: 'HEAD',
                    uri: new Uri('/com.instana.plugin.php.' . $this->pid),
                    headers: $this->headers
                )
            );

            $code = $response->getStatusCode();
            self::logDebug("Phase 3 announcement endpoint status " . $code);
            if ($code >= 200 && $code < 300) {
                $this->closed = false;
                return true;
            }

            sleep(1);
        }

        $this->closed = true;
        return false;
    }

    private function isParentProcessRequired(int $parent_pid): bool
    {
        $cmdline_args = $this->getCmdlineArgs($parent_pid);
        $executable = $cmdline_args[0];
        if (strncasecmp(PHP_OS, "win", 3) == 0) {
            return str_contains($executable, "w3wp.exe") || str_contains($executable, "httpd.exe") || 
                str_contains($executable, "httpd2.exe") || str_contains($executable, "apache2.exe") || 
                str_contains($executable, "apache24.exe");
        } else {
            return str_ends_with($executable, "httpd") || str_ends_with($executable, "httpd2") || 
            str_ends_with($executable, "httpd_64") || str_ends_with($executable, "apache2") || 
            str_ends_with($executable, "apache24") || str_ends_with($executable, "apachectl_64");
        }
    }

    private function getAnnouncementPid(): int
    {
        $sapi = php_sapi_name();
        if ($sapi != 'cgi' && $sapi != 'cgi-fcgi' && $sapi != 'apache2handler') {
            return getmypid();
        }

        $php_pid = getmypid();
        if (strncasecmp(PHP_OS, "win", 3) == 0) {
            $command = sprintf("powershell -Command \"Get-CimInstance -Class Win32_Process -Filter 'ProcessId = %s' | Select-Object -Expand ParentProcessId\"", 
            escapeshellarg(strval($php_pid)));
            $parent_pid = shell_exec(escapeshellcmd($command));
            $parent_pid = intval($parent_pid);
            return $this->isParentProcessRequired($parent_pid) ? $parent_pid : $php_pid;
        } else {
            if ($sapi == 'apache2handler' && !PHP_ZTS) {
                return $php_pid;
            }
            $parent_pid = posix_getppid();
            return $this->isParentProcessRequired($parent_pid) ? $parent_pid : $php_pid;
        }
    }

    private function getCmdlineArgs(int $pid): array
    {
        if (strncasecmp(PHP_OS, "win", 3) == 0) {
            $command = sprintf("powershell -Command \"Get-CimInstance -Class Win32_Process -Filter 'ProcessId = %s' | Select-Object -Expand CommandLine\"", escapeshellarg(strval($pid)));            
            return explode(
                " ", 
                shell_exec(escapeshellcmd($command))
            );
        } else {
            return explode("\0", file_get_contents("/proc/{$pid}/cmdline"));
        }
    }

    private function getAnnouncementPayload(): string
    {
        $announcement_pid = $this->getAnnouncementPid();
        $cmdline_args = $this->getCmdlineArgs($announcement_pid);
        $executable = $cmdline_args[0];
        $cmdline_args = array_slice($cmdline_args, 1, count($cmdline_args) - 2);

        return json_encode(array(
            "pid" => $announcement_pid,
            "pidFromParentNS" => false,
            "pidNamespace" => (strncasecmp(PHP_OS, "win", 3) == 0) ? null : readlink("/proc/self/ns/pid"),
            "name" => (strncasecmp(PHP_OS, "win", 3) == 0) ? str_replace("\"", "", $executable) : $executable,
            "args" => $cmdline_args,
            "cpuSetFileContent" => "/",
            "fd" => null,
            "inode" => null
        ));
    }

    public function getPid(): ?string
    {
        return is_null($this->pid) ? null : strval($this->pid);
    }

    public function getUuid(): ?string
    {
        return $this->agent_uuid;
    }
}
