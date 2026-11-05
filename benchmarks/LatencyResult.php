<?php

declare(strict_types=1);

namespace Slimfony\Core\Benchmarks;

/**
 * Latency distribution of one scenario, in nanoseconds.
 */
final readonly class LatencyResult
{
    public function __construct(
        public string $scenario,
        public int $iterations,
        public int $p50,
        public int $p95,
        public int $p99,
        public int $max,
    ) {}

    public function markdownRow(): string
    {
        return \sprintf('| %s | %d | %s | %s | %s | %s |', $this->scenario, $this->iterations, self::us($this->p50), self::us($this->p95), self::us($this->p99), self::us($this->max));
    }

    private static function us(int $nanoseconds): string
    {
        return \sprintf('%.1f', $nanoseconds / 1000);
    }
}
