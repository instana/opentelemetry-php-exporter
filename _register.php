<?php

declare(strict_types=1);

\OpenTelemetry\SDK\Registry::registerSpanExporterFactory('instana', \Instana\SpanExporterFactory::class);
