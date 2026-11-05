<?php

declare(strict_types=1);

namespace Slimfony\Core;

use Psr\Log\LoggerInterface;
use Slimfony\Core\Build\CompilationCache;
use Slimfony\Core\Build\ContainerCompiler;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ErrorResponder;
use Slimfony\Core\Http\MiddlewareInterface;
use Slimfony\Core\Worker\StateResetter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\RequestContext;

/**
 * Boots once (container, router, middleware chain), then handles requests. Errors become problem+json
 * responses (see ErrorResponder), also when they come from a middleware.
 *
 * Configuration files and their contract: see ContainerCompiler.
 */
final class Kernel
{
    private readonly ContainerInterface $container;
    private readonly CompiledUrlMatcher $matcher;
    private readonly ErrorResponder $errors;
    /** @var \Closure(Request): Response */
    private readonly \Closure $chain;

    /**
     * @param string|null           $cacheDir  directory written by `bin/slimfony warmup`. Used when it holds a complete
     *                                         cache and $debug is false; otherwise the config is compiled at boot.
     * @param array<string, object> $synthetic objects created before the container (logger, request context, config)
     *                                         that services need. Declare them in services.php with
     *                                         `->setSynthetic(true)->setPublic(true)`.
     */
    public function __construct(
        string $servicesFile,
        string $routesFile,
        bool $debug = false,
        ?LoggerInterface $logger = null,
        ?string $cacheDir = null,
        array $synthetic = [],
    ) {
        $compilation = null;
        if (null !== $cacheDir && !$debug) {
            $compilation = new CompilationCache($cacheDir)->load();
            if (null === $compilation) {
                $logger?->warning('No compiled cache found; compiling at boot. Run `bin/slimfony warmup` at build time.');
            }
        }
        $compilation ??= new ContainerCompiler($servicesFile, $routesFile)->compile();

        $container = $compilation->container;
        foreach ($synthetic as $id => $service) {
            $container->set($id, $service);
        }
        $this->container = $container;
        $this->matcher = new CompiledUrlMatcher($compilation->routes, new RequestContext());
        $this->errors = new ErrorResponder($debug, $logger);

        $chain = $this->dispatch(...);
        foreach (array_reverse($compilation->middlewareIds()) as $id) {
            $middleware = $container->get($id);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new \LogicException(\sprintf('Service "%s" must implement %s.', $id, MiddlewareInterface::class));
            }
            $next = $chain;
            $chain = static fn(Request $request): Response => $middleware->process($request, $next);
        }
        $this->chain = $chain;
    }

    public function handle(Request $request): Response
    {
        try {
            return ($this->chain)($request);
        } catch (\Throwable $e) { // a middleware itself failed
            return $this->errors->respond($request, $e);
        }
    }

    /**
     * Resets request-scoped services. Call after every request; a throw means the worker state is unknown.
     */
    public function reset(): void
    {
        $resetter = $this->container->get(StateResetter::class);
        $resetter instanceof StateResetter || throw new \LogicException('StateResetter service missing.');
        $resetter->reset();
    }

    private function dispatch(Request $request): Response
    {
        try {
            $this->matcher->getContext()->fromRequest($request);
            $controllerId = null;
            foreach ($this->matcher->matchRequest($request) as $key => $value) {
                if (Attributes::CONTROLLER === $key) {
                    $controllerId = $value;
                }
                $request->attributes->set((string) $key, $value);
            }
            if (!\is_string($controllerId)) {
                throw new \LogicException('Matched route has no controller.');
            }

            $controller = $this->container->get($controllerId);
            if (!\is_callable($controller)) {
                throw new \LogicException('Controller service is not invokable.');
            }
            $response = $controller($request);

            return $response instanceof Response ? $response : throw new \LogicException('Controller must return a Response.');
        } catch (\Throwable $e) {
            return $this->errors->respond($request, $e);
        }
    }
}
