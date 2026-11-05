<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'hello ' . $name;
    }
}
