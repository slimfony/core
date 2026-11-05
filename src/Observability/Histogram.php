<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

/**
 * Distribution of durations in seconds. Create it with MetricsRegistry::histogram() at boot.
 * Per observation only the matching bucket, the count and the sum (whole microseconds, so it is an atomic
 * integer increment) are written; the cumulative view is built when the metrics are rendered.
 *
 * @api
 */
final class Histogram
{
    /**
     * @param list<string> $labelNames
     * @param list<float>  $buckets    upper bounds in seconds, ascending
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly string $name,
        private readonly array $labelNames,
        private readonly array $buckets,
    ) {}

    /**
     * @param list<string> $labelValues one value per label name, in the order they were declared
     */
    public function observe(float $seconds, array $labelValues = []): void
    {
        if (\count($labelValues) !== \count($this->labelNames)) {
            throw new \InvalidArgumentException(\sprintf('Histogram %s needs %d label value(s).', $this->name, \count($this->labelNames)));
        }

        $index = \count($this->buckets); // the +Inf bucket
        foreach ($this->buckets as $i => $upperBound) {
            if ($seconds <= $upperBound) {
                $index = $i;

                break;
            }
        }

        $this->storage->add(Keys::of(Keys::BUCKET, $this->name, $labelValues, $index), 1);
        $this->storage->add(Keys::of(Keys::COUNT, $this->name, $labelValues), 1);
        $this->storage->add(Keys::of(Keys::SUM_MICROSECONDS, $this->name, $labelValues), max(0, (int) round($seconds * 1_000_000)));
    }
}
