# OpenTelemetry Wordpress Instrumentation example

This example is a fork of [OpenTelemetry Wordpress Instrumentation example](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/examples/instrumentation/Wordpress).

This example auto instruments the official [Wordpress docker image](https://hub.docker.com/_/wordpress) with [PHP SDK exporter](https://github.com/instana/opentelemetry-php-exporter).
This example auto instruments curl APIs.

The official image is extended in [`autoinstrumented-wordpress.dockerfile`](./autoinstrumented-wordpress.dockerfile) to

1. Install [OTel Wordpress instrumentation](../../../src/Instrumentation/Wordpress/) and dependencies with composer in a build stage.
2. Install [OTel PHP extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation) in the official Wordpress image and configure it into the image's PHP installation (see [`otel.php.ini`](./otel.php.ini)).
This extension is required by the Wordpress Instrumentation.
3. Copy the composer installed deps into the image. [`otel.php.ini`](./otel.php.ini) has a `auto_prepend_file=/var/www/otel/autoload.php` clause so that OTel is loaded into Wordpress sources at runtime.
4. Install [JSON Basic Authentication](https://github.com/WP-API/Basic-Auth) Wordpress plugin for enabling easy, but **!!unsecure!!** REST API authentication for development purposes.

The example has Wordpress send OTLP -> [OpenTelemetryCollector](https://opentelemetry.io/docs/collector/) -> Jaeger all in one agent.

## Running it

Configure Instana Agent for OpenTelemetry support as described at [Sending OpenTelemetry data to the Instana agent](https://www.ibm.com/docs/en/instana-observability/current?topic=opentelemetry-sending-data-instana-agent).
Enable PHP sensor

In a shell, run:

```sh
docker-compose up --build
```

Then, go to http://localhost to set up Wordpress.

If you want to use the REST API, make sure that the following settings are applied at http://localhost/wp-admin:
* Under `Plugins > Installed Plugins`, activate `JSON Basic Authentication`.
* Under `Settings > Permalinks`, something other than `Plain` is selected.


## Distributed Tracing test app 
This is an example of manual context propagation between services. Auto-instrumentation will only automatically propagate context for an incoming request if you are using a framework that we provide auto-instrumentation for (symfony, laravel, etc). Otherwise, you need to do it yourself. Since we are using curl, we need to manually propagate the context.

There are three php files which constitute a distributed app service1.php, service2.php and service3.php. 
The requests go from service1 to service2 and from service2 to service3 and extract traceparent/context headers propagated from root span(sdk.php) to child spans (service1.php, service2.php)

**To run distributed app:**

Exec in to the container and start the service1, service2 and service 3 as below:

```sh
php -S 0.0.0.0:8004 service1.php &
php -S 0.0.0.0:8002 service2.php &
php -S 0.0.0.0:8003 service3.php &
```

Trigger the call to service1 either by direct curl which is also autoinstrumented by instana exporter

```sh
curl http://localhost:8004/service1.php
```
OR

```sh
php sdk.php
```



