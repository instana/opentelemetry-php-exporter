<?php

declare(strict_types=1);

namespace Instana;

use OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class SpanExporterFactory implements SpanExporterFactoryInterface
{
    const DEFAULT_INSTANA_AGENT_HOST = '127.0.0.1';
    const DEFAULT_INSTANA_AGENT_PORT = '42699';

    public function create(): SpanExporterInterface
    {
        $host = $_SERVER['INSTANA_AGENT_HOST'] ?? self::DEFAULT_INSTANA_AGENT_HOST;
        $port = $_SERVER['INSTANA_AGENT_PORT'] ?? self::DEFAULT_INSTANA_AGENT_PORT;

        $endpoint = $host . ':' . $port;
        $timeout = 10; //s

        $transport = new InstanaTransport($endpoint, $timeout);

        $uuid = $transport->getUuid();
        $pid = $transport->getPid();
        $converter = new SpanConverter($uuid, $pid);

        return new SpanExporter($transport, $converter);
    }
}
