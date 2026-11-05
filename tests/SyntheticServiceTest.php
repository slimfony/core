<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Build\CompilationCache;
use Slimfony\Core\Build\ContainerCompiler;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Fixtures\Greeter;
use Slimfony\Core\Tests\Support\TempDir;
use Slimfony\Core\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;

/** Objects built before the container (logger, request context, env) must reach services, also from the warmed cache. */
final class SyntheticServiceTest extends TestCase
{
    private const string SERVICES = TestKernel::FIXTURES . '/Bad/synthetic_services.php';
    private const string ROUTES = TestKernel::FIXTURES . '/Bad/hello_routes.php';

    public function testServicesReceiveObjectsHandedToTheKernel(): void
    {
        $kernel = new Kernel(self::SERVICES, self::ROUTES, synthetic: [Greeter::class => new Greeter()]);

        self::assertSame('hello world', $kernel->handle(Request::create('/hello/world'))->getContent());
    }

    public function testTheSameWorksFromTheWarmedCache(): void
    {
        $cache = new TempDir();
        new CompilationCache($cache->path)->store(new ContainerCompiler(self::SERVICES, self::ROUTES)->compile());

        $kernel = new Kernel('/x', '/y', cacheDir: $cache->path, synthetic: [Greeter::class => new Greeter()]);

        self::assertSame('hello world', $kernel->handle(Request::create('/hello/world'))->getContent());
    }
}
