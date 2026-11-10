<?php

declare(strict_types=1);

namespace Slimfony\Core\Worker;

use Slimfony\Core\Observability\Counter;

/**
 * How a worker handles PHP's cycle collector (see WorkerRunner). The default leaves it to PHP and counts nothing.
 *
 * @api
 */
final readonly class GarbageCollection
{
    /**
     * @param bool         $deferred       switch the collector off while a request is handled and run it between requests, once
     *                                     `$rootsThreshold` possible cycles have piled up
     * @param int          $rootsThreshold with `$deferred`: PHP's own default is 10,000
     * @param int          $every          also run `gc_collect_cycles()` after every N requests, 0 = only as above
     * @param Counter|null $runs           counts the collector's runs (PHP's own and ours)
     * @param Counter|null $collected      counts the cycles it freed
     */
    public function __construct(
        public bool $deferred = false,
        public int $rootsThreshold = 10_000,
        public int $every = 0,
        public ?Counter $runs = null,
        public ?Counter $collected = null,
    ) {
        if ($rootsThreshold < 1) {
            throw new \InvalidArgumentException('rootsThreshold must be at least 1.');
        }
        if ($every < 0) {
            throw new \InvalidArgumentException('every must not be negative.');
        }
    }
}
