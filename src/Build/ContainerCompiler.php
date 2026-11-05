<?php

declare(strict_types=1);

namespace Slimfony\Core\Build;

use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Worker\ResetPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Compiles services.php and routes.php. This is the always-correct path (dev, tests, no cache);
 * CompilationCache stores the result at image build time so workers do not repeat this at every boot.
 *
 * services.php: returns Closure(ContainerBuilder): void.
 * routes.php:   returns Closure(RouteCollection): void; every route's '_controller' is a service id.
 *
 * @internal
 */
final class ContainerCompiler
{
    public function __construct(
        private readonly string $servicesFile,
        private readonly string $routesFile,
    ) {}

    public function compile(): Compilation
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        $container->registerForAutoconfiguration(ResetInterface::class)->addTag(ResetPass::TAG);
        $container->addCompilerPass(new ResetPass());
        $this->closure($this->servicesFile)($container);

        $routes = new RouteCollection();
        $this->closure($this->routesFile)($routes);

        foreach ($routes as $route) {
            $id = $route->getDefault(Attributes::CONTROLLER);
            if (!\is_string($id)) {
                throw new ConfigurationException(\sprintf('Every route needs a string "%s" service id.', Attributes::CONTROLLER));
            }
            $container->getDefinition($id)->setPublic(true);
        }

        // always present: a dumped container without any parameter cannot answer getParameter() at all
        if (!$container->hasParameter(Compilation::MIDDLEWARE_PARAMETER)) {
            $container->setParameter(Compilation::MIDDLEWARE_PARAMETER, []);
        }
        foreach (new Compilation($container, [])->middlewareIds() as $id) {
            $container->getDefinition($id)->setPublic(true);
        }

        $container->compile();

        return new Compilation($container, new CompiledUrlMatcherDumper($routes)->getCompiledRoutes());
    }

    /**
     * @return \Closure(object): void
     */
    private function closure(string $file): \Closure
    {
        $loaded = require $file;
        if (!$loaded instanceof \Closure) {
            throw new ConfigurationException(\sprintf('"%s" must return a Closure.', $file));
        }

        return $loaded;
    }
}
