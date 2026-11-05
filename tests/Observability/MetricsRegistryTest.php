<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Observability;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\InMemoryStorage;

final class MetricsRegistryTest extends TestCase
{
    public function testRendersExactPrometheusTextWithCumulativeBucketsAndEscapedLabels(): void
    {
        $storage = new InMemoryStorage();
        $registry = new MetricsRegistry($storage);
        $jobs = $registry->counter('jobs_total', 'Jobs done.', ['kind']);
        $duration = $registry->histogram('op_seconds', 'Operation time.', ['route'], [0.1, 0.5]);

        $jobs->inc(['a"b']);
        $jobs->inc(['a"b']);
        $duration->observe(0.05, ['x']);
        $duration->observe(0.3, ['x']);
        $duration->observe(2.0, ['x']);
        $storage->add('c|metric_from_an_older_deploy|', 7); // not declared: must not show up

        self::assertSame(
            <<<'TXT'
                # HELP jobs_total Jobs done.
                # TYPE jobs_total counter
                jobs_total{kind="a\"b"} 2
                # HELP op_seconds Operation time.
                # TYPE op_seconds histogram
                op_seconds_bucket{route="x",le="0.1"} 1
                op_seconds_bucket{route="x",le="0.5"} 2
                op_seconds_bucket{route="x",le="+Inf"} 3
                op_seconds_sum{route="x"} 2.35
                op_seconds_count{route="x"} 3

                TXT,
            $registry->render(),
        );
    }

    public function testNumericAndCommaLabelValuesSurviveTheRoundTrip(): void
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $counter = $registry->counter('events_total', 'Events.', ['code', 'name']);

        $counter->inc(['200', 'a,b']);
        $counter->inc(['42', 'plain']);

        $out = $registry->render();
        self::assertStringContainsString('events_total{code="200",name="a,b"} 1', $out);
        self::assertStringContainsString('events_total{code="42",name="plain"} 1', $out);
    }

    /**
     * @param \Closure(MetricsRegistry): mixed $declare
     */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidMetricDeclarationsFailAtBoot(\Closure $declare): void
    {
        $this->expectException(ConfigurationException::class);

        $declare(new MetricsRegistry(new InMemoryStorage()));
    }

    /**
     * @return iterable<string, array{\Closure(MetricsRegistry): mixed}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'metric name with a dash' => [static fn(MetricsRegistry $r): mixed => $r->counter('bad-name', 'x')];
        yield 'label name with a dot' => [static fn(MetricsRegistry $r): mixed => $r->counter('ok_total', 'x', ['a.b'])];
        yield 'reserved le label on a histogram' => [static fn(MetricsRegistry $r): mixed => $r->histogram('h_seconds', 'x', ['le'])];
        yield 'buckets not ascending' => [static fn(MetricsRegistry $r): mixed => $r->histogram('h_seconds', 'x', [], [0.5, 0.1])];
        yield 'the same name twice' => [static function (MetricsRegistry $r): mixed {
            $r->counter('dup_total', 'x');

            return $r->counter('dup_total', 'x');
        }];
    }
}
