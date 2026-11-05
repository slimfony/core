<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Contracts\Service\ResetInterface;

final class BrokenReset implements ResetInterface
{
    public function reset(): void
    {
        throw new \RuntimeException('cannot reset');
    }
}
