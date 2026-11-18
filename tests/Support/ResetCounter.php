<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Symfony\Contracts\Service\ResetInterface;

final class ResetCounter implements ResetInterface
{
    public int $resets = 0;

    #[\Override]
    public function reset(): void
    {
        ++$this->resets;
    }
}
