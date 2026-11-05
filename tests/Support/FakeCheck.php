<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Slimfony\Core\Health\ReadinessCheck;

/** Test double for a readiness check that can be switched between healthy and failing. */
final class FakeCheck implements ReadinessCheck
{
    public bool $healthy = true;
    public int $calls = 0;

    #[\Override]
    public function name(): string
    {
        return 'fake';
    }

    #[\Override]
    public function check(): void
    {
        ++$this->calls;
        if (!$this->healthy) {
            throw new \RuntimeException('connection refused to 10.0.0.5 (internal detail)');
        }
    }
}
