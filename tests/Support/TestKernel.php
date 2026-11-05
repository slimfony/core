<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Psr\Log\LoggerInterface;
use Slimfony\Core\Kernel;

/** Builds a Kernel for the shared fixture application in tests/Fixtures. */
final class TestKernel
{
    public const string FIXTURES = __DIR__ . '/../Fixtures';

    private function __construct() {}

    public static function create(bool $debug = false, ?LoggerInterface $logger = null, ?string $cacheDir = null): Kernel
    {
        return new Kernel(self::FIXTURES . '/services.php', self::FIXTURES . '/routes.php', $debug, $logger, $cacheDir);
    }
}
