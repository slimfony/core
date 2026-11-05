<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Build\CompilationCache;
use Slimfony\Core\Build\ContainerCompiler;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Symfony\Component\HttpFoundation\Request;

final class WarmupTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixtures';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/slimfony-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $files = glob($this->cacheDir . '/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function testBootFromTheWarmedCacheServesTheAppWithoutTouchingTheConfigFiles(): void
    {
        new CompilationCache($this->cacheDir)->store(new ContainerCompiler(self::FIXTURES . '/services.php', self::FIXTURES . '/routes.php')->compile());

        // the config files do not even exist here: everything must come from the cache
        $kernel = new Kernel('/nonexistent/services.php', '/nonexistent/routes.php', cacheDir: $this->cacheDir);

        $response = $kernel->handle(Request::create('/hello/world'));
        self::assertSame('hello world', $response->getContent());
        self::assertSame('200', $response->headers->get('X-Seen-Status'), 'middleware chain comes from the cached container');
        self::assertSame(404, $kernel->handle(Request::create('/nope'))->getStatusCode());
        self::assertSame(405, $kernel->handle(Request::create('/hello/world', 'POST'))->getStatusCode());
    }

    public function testWithoutACacheItCompilesAtBootAndSaysSo(): void
    {
        $logger = new RecordingLogger();

        $kernel = new Kernel(self::FIXTURES . '/services.php', self::FIXTURES . '/routes.php', logger: $logger, cacheDir: $this->cacheDir);

        self::assertSame(200, $kernel->handle(Request::create('/hello/world'))->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('warning: No compiled cache found', $logger->records[0]);
    }

    public function testBrokenConfigurationFailsTheWarmupAndLeavesNoCacheBehind(): void
    {
        try {
            new CompilationCache($this->cacheDir)->store(new ContainerCompiler(self::FIXTURES . '/services.php', self::FIXTURES . '/Bad/route_without_controller.php')->compile());
            self::fail('A broken configuration must fail the image build.');
        } catch (\LogicException) {
            self::assertFileDoesNotExist($this->cacheDir . '/manifest.php');
        }
    }
}
