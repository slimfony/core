<?php

declare(strict_types=1);

namespace Slimfony\Core\Build;

use Slimfony\Core\Worker\ResetPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns services.php and routes.php into a compiled container and compiled route table.
 *
 * compile() is the always-correct path (used when there is no cache, and in dev/tests).
 * warmup() runs once at image build time and writes the result to PHP files; workers then only
 * require() them (opcache shares them between threads) instead of compiling at every boot.
 */
final class Compiler
{
    /**
     * @return array{ContainerBuilder, array<mixed>} compiled container and compiled route table
     */
    public static function compile(string $servicesFile, string $routesFile): array
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        $container->registerForAutoconfiguration(ResetInterface::class)->addTag(ResetPass::TAG);
        $container->addCompilerPass(new ResetPass());
        self::closure($servicesFile)($container);

        $routes = new RouteCollection();
        self::closure($routesFile)($routes);

        foreach ($routes as $route) {
            $id = $route->getDefault('_controller');
            if (!\is_string($id)) {
                throw new \LogicException('Every route needs a string "_controller" service id.');
            }
            $container->getDefinition($id)->setPublic(true);
        }

        // always present: a dumped container without any parameter cannot answer getParameter() at all
        if (!$container->hasParameter('slimfony.middleware')) {
            $container->setParameter('slimfony.middleware', []);
        }
        $ids = self::middlewareIds($container->getParameter('slimfony.middleware'));
        foreach ($ids as $id) {
            $container->getDefinition($id)->setPublic(true);
        }

        $container->compile();

        return [$container, (new CompiledUrlMatcherDumper($routes))->getCompiledRoutes()];
    }

    /**
     * @return list<string>
     */
    public static function middlewareIds(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new \LogicException('Parameter "slimfony.middleware" must be a list of service ids.');
        }
        foreach ($value as $id) {
            if (!\is_string($id)) {
                throw new \LogicException('Parameter "slimfony.middleware" must be a list of service ids.');
            }
        }

        return $value;
    }

    /**
     * Writes the compiled container and routes into $cacheDir. Throws on any configuration error, so a broken
     * image build fails here and never ships. The container must not contain secrets: only %env()% placeholders.
     */
    public static function warmup(string $servicesFile, string $routesFile, string $cacheDir): void
    {
        [$container, $routes] = self::compile($servicesFile, $routesFile);

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o755, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(\sprintf('Cannot create cache directory "%s".', $cacheDir));
        }

        $class = 'SlimfonyContainer' . bin2hex(random_bytes(6)); // unique: several caches may live in one process (tests)
        $dumped = (new PhpDumper($container))->dump(['class' => $class]);
        if (!\is_string($dumped)) {
            throw new \LogicException('Container dump did not produce a single file.');
        }

        self::write($cacheDir . '/' . $class . '.container.php', $dumped);
        self::write($cacheDir . '/' . $class . '.routes.php', '<?php return ' . var_export($routes, true) . ";\n");
        // the manifest goes last and atomically: a half-written cache is never picked up
        self::write($cacheDir . '/manifest.php', '<?php return ' . var_export(['class' => $class], true) . ";\n");
    }

    /**
     * @return array{ContainerInterface, array<mixed>}|null null when there is no (complete) cache
     */
    public static function fromCache(string $cacheDir): ?array
    {
        $manifestFile = $cacheDir . '/manifest.php';
        if (!is_file($manifestFile)) {
            return null;
        }

        $manifest = require $manifestFile;
        $class = \is_array($manifest) ? ($manifest['class'] ?? null) : null;
        if (!\is_string($class) || 1 !== preg_match('/^SlimfonyContainer[0-9a-f]{12}$/D', $class)) {
            throw new \LogicException('Corrupt cache manifest; run warmup again.');
        }

        if (!class_exists($class, false)) {
            require $cacheDir . '/' . $class . '.container.php';
        }
        $routes = require $cacheDir . '/' . $class . '.routes.php';
        if (!is_subclass_of($class, ContainerInterface::class) || !\is_array($routes)) {
            throw new \LogicException('Corrupt cache; run warmup again.');
        }

        return [new $class(), $routes];
    }

    private static function write(string $file, string $content): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (false === file_put_contents($tmp, $content) || !rename($tmp, $file)) {
            throw new \RuntimeException(\sprintf('Cannot write "%s".', $file));
        }
    }

    /**
     * @return \Closure(object): void
     */
    private static function closure(string $file): \Closure
    {
        $loaded = require $file;
        if (!$loaded instanceof \Closure) {
            throw new \LogicException(\sprintf('"%s" must return a Closure.', $file));
        }

        return $loaded;
    }
}
