<?php

declare(strict_types=1);

namespace Slimfony\Core\Benchmarks;

use Slimfony\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Measures what the core itself adds to a request: Kernel::handle() with the production middleware chain, per request,
 * in-process. It excludes HTTP parsing and the network (Caddy/FrankenPHP) and the application's own work.
 * Each request is timed individually, so the result is a real latency distribution, not an average.
 */
final class LatencyBenchmark
{
    public function __construct(
        private readonly Kernel $kernel,
        private int $warmup = 3000 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('warmup must not be negative.'); },
        private int $iterations = 30000 { set => $value >= 1 ? $value : throw new \InvalidArgumentException('iterations must be at least 1.'); },
    ) {}

    /**
     * @param \Closure(): Request $makeRequest builds a fresh request (outside the timed region)
     */
    public function measure(string $scenario, \Closure $makeRequest, int $expectedStatus): LatencyResult
    {
        for ($i = 0; $i < $this->warmup; ++$i) {
            $this->once($makeRequest, $expectedStatus);
        }

        $samples = [];
        for ($i = 0; $i < $this->iterations; ++$i) {
            $samples[] = $this->once($makeRequest, $expectedStatus);
        }
        sort($samples);

        return new LatencyResult(
            $scenario,
            $this->iterations,
            self::percentile($samples, 50),
            self::percentile($samples, 95),
            self::percentile($samples, 99),
            $samples[$this->iterations - 1],
        );
    }

    /**
     * @param \Closure(): Request $makeRequest
     */
    private function once(\Closure $makeRequest, int $expectedStatus): int
    {
        $request = $makeRequest();

        $start = hrtime(true);
        $response = $this->kernel->handle($request);
        $elapsed = hrtime(true) - $start;

        $this->kernel->reset(); // as the worker does after every request (not timed)
        if ($response->getStatusCode() !== $expectedStatus) {
            throw new \LogicException(\sprintf('The scenario is broken: expected status %d, got %d. A benchmark of an error path proves nothing.', $expectedStatus, $response->getStatusCode()));
        }

        return $elapsed;
    }

    /**
     * @param list<int> $sortedSamples
     */
    private static function percentile(array $sortedSamples, int $percent): int
    {
        return $sortedSamples[max(0, (int) ceil($percent / 100 * \count($sortedSamples)) - 1)];
    }
}
