<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

/** A clock the test moves by hand: pass `$clock(...)` wherever a `Closure(): float` of seconds is expected. */
final class TestClock
{
    public function __construct(public float $now = 1000.0) {}

    public function __invoke(): float
    {
        return $this->now;
    }
}
