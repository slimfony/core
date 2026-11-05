<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testAutoloadWorks(): void
    {
        self::assertTrue(class_exists(\Symfony\Component\HttpFoundation\Request::class));
    }
}
