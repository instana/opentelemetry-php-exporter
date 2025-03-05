# Instana OpenTelemetry PHP Exporter

Instana exporter for OpenTelemetry.

## Documentation

https://www.ibm.com/docs/en/instana-observability/current?topic=php-opentelemetry-exporter

## Installing via Composer

Install Composer in a common location or in your project

```bash
curl -s https://getcomposer.org/installer | php
```

Install via Composer

```bash
composer require instana/opentelemetry-php-exporter
```

## Usage

Utilizing the OpenTelemetry PHP SDK, we can send spans natively to Instana, by providing an OpenTelemetry span processor our `SpanExporterInterface`.

This can be manually constructed, or created from the `SpanExporterFactory`. See the factory implementation for how to manually construct the `SpanExporter`. The factory reads from two environment variables which can be set according, else will fallback onto the following defaults

```bash
INSTANA_AGENT_HOST=127.0.0.1
INSTANA_AGENT_PORT=42699
```

The service name that is visible in the Instana UI can be configured with the following environment variables. OpenTelemetry provides `OTEL_SERVICE_NAME` (see documentation [here](https://opentelemetry.io/docs/languages/sdk-configuration/general/#otel_service_name)) as a way to customize this within the SDK. We also provide `INSTANA_SERVICE_NAME` which will be taken as the highest precedence.

```bash
INSTANA_SERVICE_NAME=custom-service-name
```

## Example

```php
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

$tracerProvider = new TracerProvider(
    new SimpleSpanProcessor(
        Registry::spanExporterFactory("instana")->create()
    )
);
$tracer = $tracerProvider->getTracer('io.instana.opentelemetry.php');

$span = $tracer->spanBuilder('root')->startSpan();
$span->setAttribute('remote_ip', '1.2.3.4')
    ->setAttribute('country', 'CAN');
$span->addEvent('generated_session', [
    'id' => md5((string) microtime(true)),
]);
$span->end();

$tracerProvider->shutdown();
```
