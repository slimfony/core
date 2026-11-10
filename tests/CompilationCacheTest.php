<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Build\Compilation;
use Slimfony\Core\Build\CompilationCache;
use Slimfony\Core\Build\ContainerCompiler;
use Slimfony\Core\Exception\BuildException;
use Slimfony\Core\Tests\Support\TempDir;
use Slimfony\Core\Tests\Support\TestKernel;
use Symfony\Component\DependencyInjection\Container;

final class CompilationCacheTest extends TestCase
{
    public function testWhereThereIsNoCacheThereIsNothingToLoad(): void
    {
        $cache = new TempDir();

        self::assertNull(new CompilationCache($cache->path)->load());
    }

    public function testACorruptManifestIsRefusedInsteadOfBeingRequired(): void
    {
        $cache = new TempDir();
        mkdir($cache->path);
        file_put_contents($cache->path . '/manifest.php', "<?php return ['class' => 'Evil\\\\Class'];\n"); // not the name of a dumped container

        $this->expectException(BuildException::class);

        new CompilationCache($cache->path)->load();
    }

    public function testOnlyAFreshlyCompiledContainerCanBeStored(): void
    {
        $cache = new TempDir();

        $this->expectException(BuildException::class);

        new CompilationCache($cache->path)->store(new Compilation(new Container(), []));
    }

    public function testAnUnusableCacheDirectoryIsReportedAtBuildTime(): void
    {
        $cache = new TempDir();
        mkdir($cache->path);
        file_put_contents($cache->path . '/a-file', 'x'); // a file where the directory should be

        $this->expectException(BuildException::class);

        new CompilationCache($cache->path . '/a-file/cache')->store(new ContainerCompiler(TestKernel::FIXTURES . '/services.php', TestKernel::FIXTURES . '/routes.php')->compile());
    }
}
