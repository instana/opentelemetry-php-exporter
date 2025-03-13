<?php

declare(strict_types=1);

namespace Instana;

use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\SpanConverterInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;

use Instana\SpanKind as InstanaSpanKind;

use Exception;
use OpenTelemetry\SemConv\ResourceAttributes;

use function max;

class SpanConverter implements SpanConverterInterface
{
    const OTEL_KEY_STATUS_CODE = 'status_code';
    const OTEL_KEY_STATUS_DESCRIPTION = 'error';
    const OTEL_KEY_INSTRUMENTATION_SCOPE_NAME = 'scope.name';
    const OTEL_KEY_INSTRUMENTATION_SCOPE_VERSION = 'scope.version';
    const OTEL_KEY_DROPPED_ATTRIBUTES_COUNT = 'dropped_attributes_count';
    const OTEL_KEY_DROPPED_EVENTS_COUNT = 'dropped_events_count';
    const OTEL_KEY_DROPPED_LINKS_COUNT = 'dropped_links_count';

    private readonly string $defaultServiceName;

    public function __construct(
        private ?string $agentUuid = null,
        private ?string $agentPid = null
    ) {
        $this->defaultServiceName = ResourceInfoFactory::defaultResource()->getAttributes()->get(ResourceAttributes::SERVICE_NAME);
    }

    public function convert(iterable $spans): array
    {
        $aggregate = [];
        foreach ($spans as $span) {
            $aggregate[] = $this->convertSpan($span);
        }

        return $aggregate;
    }

    private function convertSpan(SpanDataInterface $span): array
    {
        $startTimestamp = self::nanosToMillis($span->getStartEpochNanos());
        $endTimestamp = self::nanosToMillis($span->getEndEpochNanos());

        if (is_null($this->agentUuid) || is_null($this->agentPid)) {
            throw new Exception('Failed to get agentUuid or agentPid');
        }

        $instanaSpan = [
            'n' => 'php',
            't' => $span->getTraceId(),
            's' => $span->getSpanId(),
            'ts' => $startTimestamp,
            'd' => max(0, $endTimestamp - $startTimestamp),
            'f' => array('e' => $this->agentPid, 'h' => $this->agentUuid),
            'data' => []
        ];

        if ($span->getParentContext()->isValid()) {
            $instanaSpan['p'] = $span->getParentSpanId();
            $instanaSpan['n'] = 'sdk';
        }

        $convertedKind = SpanConverter::toSpanKind($span);
        if (!is_null($convertedKind)) {
            $instanaSpan['k'] = $convertedKind;
        }

        $serviceName = $span->getResource()->getAttributes()->get(ResourceAttributes::SERVICE_NAME) ?? $this->defaultServiceName;
        $instanaSpan['data']['service'] = $_SERVER['INSTANA_SERVICE_NAME'] ?? $serviceName;

        $instanaSpan['data']['sdk']['name'] = $span->getName() ?: 'sdk';
        $instanaSpan['data']['sdk']['custom']['tags'] = [];
        foreach ($span->getResource()->getAttributes() as $key => $attrb) {
            if (str_contains($key, 'service.')) {
                continue;
            }
            $instanaSpan['data']['sdk']['custom']['tags'][$key] = $attrb;
        }

        foreach ($span->getAttributes() as $key => $attrb) {
            self::setOrAppend('attributes', $instanaSpan['data']['sdk']['custom']['tags'], array($key => $attrb));
        }

        foreach ($span->getEvents() as $event) {
            self::setOrAppend('events', $instanaSpan['data']['sdk']['custom']['tags'], array($event->getName() => self::convertEvent($event)));
        }

        foreach ($span->getInstrumentationScope()->getAttributes() as $key => $value) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array($key => $value));
        }

        if (!empty($span->getInstrumentationScope()->getName())) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array(self::OTEL_KEY_INSTRUMENTATION_SCOPE_NAME => $span->getInstrumentationScope()->getName()));
        }

        if (!is_null($span->getInstrumentationScope()->getVersion())) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array(self::OTEL_KEY_INSTRUMENTATION_SCOPE_VERSION => $span->getInstrumentationScope()->getVersion()));
        }

        if ($span->getStatus()->getCode() !== StatusCode::STATUS_UNSET) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array(self::OTEL_KEY_STATUS_CODE => $span->getStatus()->getCode()));
        }

        if ($span->getStatus()->getCode() === StatusCode::STATUS_ERROR) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array(self::OTEL_KEY_STATUS_DESCRIPTION => $span->getStatus()->getDescription()));
        }

        $droppedAttributes = $span->getAttributes()->getDroppedAttributesCount()
            + $span->getInstrumentationScope()->getAttributes()->getDroppedAttributesCount()
            + $span->getResource()->getAttributes()->getDroppedAttributesCount();

        if ($droppedAttributes > 0) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array(self::OTEL_KEY_DROPPED_ATTRIBUTES_COUNT => $droppedAttributes));
        }

        if ($span->getTotalDroppedEvents() > 0) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array(self::OTEL_KEY_DROPPED_EVENTS_COUNT => $span->getTotalDroppedEvents()));
        }

        if ($span->getTotalDroppedLinks() > 0) {
            self::setOrAppend('otel', $instanaSpan['data']['sdk']['custom']['tags'], array(self::OTEL_KEY_DROPPED_LINKS_COUNT => $span->getTotalDroppedLinks()));
        }
        
        $extraHeaders = [];
        if (isset($_ENV['OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS'])) {
            $extraHeaders = array_merge(
                $extraHeaders,
                explode(",", $_ENV['OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS'])
            );
        }
        if (isset($_ENV['OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS'])) {
            $extraHeaders += array_merge(
                $extraHeaders,
                explode(",", $_ENV['OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS'])
            );
        }

        if (array_key_exists('attributes', $instanaSpan['data']['sdk']['custom']['tags'])) {
            $keys = array_filter($instanaSpan['data']['sdk']['custom']['tags']['attributes'], function ($k)  use ($extraHeaders) {
                $matches = [];
                return preg_match('/http\.(request|response)\.header\.(.+)/', $k, $matches) &&
                    !in_array($matches[2], $extraHeaders);
            }, ARRAY_FILTER_USE_KEY);

            foreach ($keys as $k => $v) {
                unset($instanaSpan['data']['sdk']['custom']['tags']['attributes'][$k]);
            }
        }

        self::unsetEmpty($instanaSpan['data']);

        return $instanaSpan;
    }

    private static function toSpanKind(SpanDataInterface $span): ?int
    {
        return match ($span->getKind()) {
            SpanKind::KIND_SERVER => InstanaSpanKind::ENTRY,
            SpanKind::KIND_CLIENT => InstanaSpanKind::EXIT,
            SpanKind::KIND_PRODUCER => InstanaSpanKind::EXIT,
            SpanKind::KIND_CONSUMER => InstanaSpanKind::ENTRY,
            SpanKind::KIND_INTERNAL => InstanaSpanKind::INTERMEDIATE,
            default => null,
        };
    }

    private static function nanosToMillis(int $nanoseconds): int
    {
        return intdiv($nanoseconds, ClockInterface::NANOS_PER_MILLISECOND);
    }

    private static function setOrAppend(string $key, array &$arr, mixed $value): void
    {
        if (array_key_exists($key, $arr)) {
            if (!is_array($arr[$key])) {
                $arr[$key] = array($arr[$key]);
            }
            $arr[$key] += is_array($value) ? $value : array($value);
        } else {
            $arr[$key] = $value;
        }
    }

    private static function unsetEmpty(array &$arr): void
    {
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                self::unsetEmpty($arr[$key]);
                if (empty($arr[$key])) {
                    unset($arr[$key]);
                }
            } elseif (is_null($value)) {
                unset($arr[$key]);
            }
        }
    }

    private static function convertEvent(EventInterface $event): string
    {
        if (count($event->getAttributes()) === 0) {
            return "{}";
        }

        $res = json_encode(array(
            'value' => $event->getAttributes()->toArray(),
            'timestamp' => self::nanosToMillis($event->getEpochNanos())
        ));

        return ($res === false) ? "{}" : $res;
    }
}
