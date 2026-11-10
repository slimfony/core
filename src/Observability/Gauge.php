<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

/**
 * A value that goes up and down (memory in bytes, queue depth, open connections). Whole numbers in base units:
 * bytes, items, connections. Create it with MetricsRegistry::gauge() at boot.
 *
 * @api
 */
final class Gauge
{
    /**
     * @internal create gauges with MetricsRegistry::gauge()
     *
     * @param list<string> $labelNames
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly string $name,
        private readonly array $labelNames,
    ) {}

    /**
     * @param list<string> $labelValues one value per label name, in the order they were declared
     */
    public function set(int $value, array $labelValues = []): void
    {
        if (\count($labelValues) !== \count($this->labelNames)) {
            throw new \InvalidArgumentException(\sprintf('Gauge %s needs %d label value(s).', $this->name, \count($this->labelNames)));
        }

        $this->storage->set(Keys::of(Keys::GAUGE, $this->name, $labelValues), $value);
    }
}
