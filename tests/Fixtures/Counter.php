<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Contracts\Service\ResetInterface;

/** Request-scoped state that must not survive into the next request. */
final class Counter implements ResetInterface
{
    public int $n = 0;

    public function reset(): void
    {
        $this->n = 0;
    }
}
