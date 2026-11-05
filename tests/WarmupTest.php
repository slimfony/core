<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Build\CompilationCache;
use Slimfony\Core\Build\ContainerCompiler;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TempDir;
use Slimfony\Core\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;

final class WarmupTest extends TestCase
{
    private const string FIXTURES = TestKernel::FIXTURES;

    public function testBootFromTheWarmedCacheServesTheAppWithoutTouchingTheConfigFiles(): void
    {
        $cache = new TempDir();
        new CompilationCache($cache->path)->store(new ContainerCompiler(self::FIXTURES . '/services.php', self::FIXTURES . '/routes.php')->compile());

        // the config files do not even exist here: everything must come from the cache
        $kernel = new Kernel('/nonexistent/services.php', '/nonexistent/routes.php', cacheDir: $cache->path);

        $response = $kernel->handle(Request::create('/hello/world'));
        self::assertSame('hello world', $response->getContent());
        self::assertSame('200', $response->headers->get('X-Seen-Status'), 'middleware chain comes from the cached container');
        self::assertSame(404, $kernel->handle(Request::create('/nope'))->getStatusCode());
        self::assertSame(405, $kernel->handle(Request::create('/hello/world', 'POST'))->getStatusCode());
    }

    public function testWithoutACacheItCompilesAtBootAndSaysSo(): void
    {
        $logger = new RecordingLogger();

        $kernel = TestKernel::create(logger: $logger, cacheDir: new TempDir()->path);

        self::assertSame(200, $kernel->handle(Request::create('/hello/world'))->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('warning: No compiled cache found', $logger->records[0]);
    }

    public function testBrokenConfigurationFailsTheWarmupAndLeavesNoCacheBehind(): void
    {
        $cache = new TempDir();

        try {
            new CompilationCache($cache->path)->store(new ContainerCompiler(self::FIXTURES . '/services.php', self::FIXTURES . '/Bad/route_without_controller.php')->compile());
            self::fail('A broken configuration must fail the image build.');
        } catch (ConfigurationException) {
            self::assertFileDoesNotExist($cache->path . '/manifest.php');
        }
    }
}
