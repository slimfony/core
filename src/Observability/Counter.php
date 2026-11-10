<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

/**
 * A counter that only goes up. Create it with MetricsRegistry::counter() at boot.
 *
 * @api
 */
final class Counter
{
    /**
     * @internal create counters with MetricsRegistry::counter()
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
    public function inc(array $labelValues = [], int $by = 1): void
    {
        if (\count($labelValues) !== \count($this->labelNames) || $by < 0) {
            throw new \InvalidArgumentException(\sprintf('Counter %s needs %d label value(s) and a non-negative step.', $this->name, \count($this->labelNames)));
        }

        $this->storage->add(Keys::of(Keys::COUNTER, $this->name, $labelValues), $by);
    }
}
