<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\InMemoryStorage;

final class GaugeTest extends TestCase
{
    public function testAGaugeShowsTheLastValueNotASumAndIsRenderedWithItsOwnType(): void
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $memory = $registry->gauge('worker_memory_bytes', 'Memory used by a worker.', ['worker']);
        $depth = $registry->gauge('queue_depth', 'Messages waiting.');

        $memory->set(1_000_000, ['api-1']);
        $memory->set(1_250_000, ['api-1']); // replaces, does not add
        $memory->set(900_000, ['api-2']);
        $depth->set(212);
        $depth->set(0);

        self::assertSame(
            <<<'TXT'
                # HELP queue_depth Messages waiting.
                # TYPE queue_depth gauge
                queue_depth 0
                # HELP worker_memory_bytes Memory used by a worker.
                # TYPE worker_memory_bytes gauge
                worker_memory_bytes{worker="api-1"} 1250000
                worker_memory_bytes{worker="api-2"} 900000

                TXT,
            $registry->render(),
        );
    }

    public function testAGaugeNeedsExactlyTheDeclaredLabels(): void
    {
        $gauge = new MetricsRegistry(new InMemoryStorage())->gauge('depth', 'x', ['queue']);

        $this->expectException(\InvalidArgumentException::class);
        $gauge->set(1);
    }
}
