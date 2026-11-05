<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Build\Compiler;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Fixtures\Greeter;
use Symfony\Component\HttpFoundation\Request;

/** Objects built before the container (logger, request context, env) must reach services, also from the warmed cache. */
final class SyntheticServiceTest extends TestCase
{
    public function testServicesReceiveObjectsHandedToTheKernel(): void
    {
        $greeter = new Greeter();
        $kernel = new Kernel(
            __DIR__ . '/Fixtures/Bad/synthetic_services.php',
            __DIR__ . '/Fixtures/Bad/hello_routes.php',
            synthetic: [Greeter::class => $greeter],
        );

        self::assertSame('hello world', $kernel->handle(Request::create('/hello/world'))->getContent());
    }

    public function testTheSameWorksFromTheWarmedCache(): void
    {
        $dir = sys_get_temp_dir() . '/slimfony-test-' . bin2hex(random_bytes(6));
        Compiler::warmup(__DIR__ . '/Fixtures/Bad/synthetic_services.php', __DIR__ . '/Fixtures/Bad/hello_routes.php', $dir);

        try {
            $kernel = new Kernel('/x', '/y', cacheDir: $dir, synthetic: [Greeter::class => new Greeter()]);

            self::assertSame('hello world', $kernel->handle(Request::create('/hello/world'))->getContent());
        } finally {
            $files = glob($dir . '/*');
            foreach (false === $files ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
