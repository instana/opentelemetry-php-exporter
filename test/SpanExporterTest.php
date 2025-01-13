<?php

declare(strict_types=1);

namespace Instana\Test;

use Instana\SpanExporter;
use Instana\SpanConverter;
use Instana\Test\Util\SpanData;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;

use RuntimeException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanExporter::class)]
class SpanExporterTest extends TestCase
{
    private SpanExporter $exporter;

    public function setUp(): void
    {
        $transportMock = $this->createMock(TransportInterface::class);
        $transportMock
            ->expects($this->any())
            ->method('send')
            ->willReturn(new CompletedFuture('Payload successfully sent'));
        $transportMock
            ->expects($this->any())
            ->method('shutdown')
            ->willReturn(true);
        $transportMock
            ->expects($this->any())
            ->method('forceFlush')
            ->willReturn(true);

        $this->exporter = new SpanExporter(
            $transportMock,
            new SpanConverter('0123456abcdef', '12345')
        );
    }

    public function test_calls_to_transportinterface(): void
    {
        $this->assertTrue($this->exporter->shutdown());
        $this->assertTrue($this->exporter->forceFlush());
    }

    public function test_successful_export(): void
    {
        $batch = array(new SpanData(), new SpanData());
        $ret = $this->exporter->export($batch);

        $this->assertSame($ret::class, CompletedFuture::class);
    }
}
