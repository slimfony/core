<?php

declare(strict_types=1);

namespace Slimfony\Core\Build;

use Slimfony\Core\Exception\BuildException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

/**
 * PHP-file cache of a Compilation. store() runs once at image build time (bin/slimfony warmup);
 * workers then only require() the files, which opcache shares between threads.
 * The container must not contain secrets: only %env()% placeholders (a test pins this).
 *
 * @internal
 */
final class CompilationCache
{
    public function __construct(private readonly string $directory) {}

    public function store(Compilation $compilation): void
    {
        $container = $compilation->container;
        if (!$container instanceof ContainerBuilder) {
            throw new BuildException('Only a freshly compiled container can be stored.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new BuildException(\sprintf('Cannot create cache directory "%s".', $this->directory));
        }

        $class = 'SlimfonyContainer' . bin2hex(random_bytes(6)); // unique: several caches may live in one process (tests)
        $dumped = new PhpDumper($container)->dump(['class' => $class]);
        if (!\is_string($dumped)) {
            throw new BuildException('Container dump did not produce a single file.');
        }

        $this->write($class . '.container.php', $dumped);
        $this->write($class . '.routes.php', '<?php return ' . var_export($compilation->routes, true) . ";\n");
        // the manifest goes last and atomically: a half-written cache is never picked up
        $this->write('manifest.php', '<?php return ' . var_export(['class' => $class], true) . ";\n");
    }

    /**
     * @return Compilation|null null when there is no (complete) cache
     */
    public function load(): ?Compilation
    {
        $manifestFile = $this->directory . '/manifest.php';
        if (!is_file($manifestFile)) {
            return null;
        }

        $manifest = require $manifestFile;
        $class = \is_array($manifest) ? ($manifest['class'] ?? null) : null;
        if (!\is_string($class) || 1 !== preg_match('/^SlimfonyContainer[0-9a-f]{12}$/D', $class)) {
            throw new BuildException('Corrupt cache manifest; run warmup again.');
        }

        if (!class_exists($class, false)) {
            require $this->directory . '/' . $class . '.container.php';
        }
        $routes = require $this->directory . '/' . $class . '.routes.php';
        if (!is_subclass_of($class, ContainerInterface::class) || !\is_array($routes)) {
            throw new BuildException('Corrupt cache; run warmup again.');
        }

        return new Compilation(new $class(), $routes);
    }

    private function write(string $name, string $content): void
    {
        $file = $this->directory . '/' . $name;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (false === file_put_contents($tmp, $content) || !rename($tmp, $file)) {
            throw new BuildException(\sprintf('Cannot write "%s".', $file));
        }
    }
}
