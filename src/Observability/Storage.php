<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

/**
 * Where metric values live: a map of keys to integers that can only grow.
 * The default is ApcuStorage (shared by all worker threads); the interface exists because the store is an
 * external boundary (a Redis-backed one fits on-prem setups without APCu).
 *
 * @api
 */
interface Storage
{
    /** Atomically adds $by to the value under $key, creating it at 0 first. */
    public function add(string $key, int $by): void;

    /** Replaces the value under $key (gauges); unlike add(), it does not accumulate. */
    public function set(string $key, int $value): void;

    /**
     * @return array<string, int> every key with its current value
     */
    public function all(): array;
}
