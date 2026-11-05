<?php

declare(strict_types=1);

namespace Slimfony\Core;

use Psr\Log\LoggerInterface;
use Slimfony\Core\Exception\HttpException;
use Slimfony\Core\Http\MiddlewareInterface;
use Slimfony\Core\Http\Problem;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Boots once (container compile, router compile, middleware chain), then handles requests.
 *
 * services.php: returns Closure(ContainerBuilder): void. Optional parameter
 *   'slimfony.middleware' = list of service ids (outermost first).
 * routes.php:   returns Closure(RouteCollection): void. Route '_controller' is a service id
 *   whose service is invokable: __invoke(Request): Response.
 */
final class Kernel
{
    private readonly ContainerBuilder $container;
    private readonly CompiledUrlMatcher $matcher;
    /** @var \Closure(Request): Response */
    private readonly \Closure $chain;

    public function __construct(
        string $servicesFile,
        string $routesFile,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        self::load($servicesFile)($container);

        $routes = new RouteCollection();
        self::load($routesFile)($routes);

        foreach ($routes as $route) {
            $id = $route->getDefault('_controller');
            if (!\is_string($id)) {
                throw new \LogicException('Every route needs a string "_controller" service id.');
            }
            $container->getDefinition($id)->setPublic(true);
        }

        $middlewareIds = $container->hasParameter('slimfony.middleware') ? $container->getParameter('slimfony.middleware') : [];
        if (!\is_array($middlewareIds) || !array_is_list($middlewareIds)) {
            throw new \LogicException('Parameter "slimfony.middleware" must be a list of service ids.');
        }
        foreach ($middlewareIds as $id) {
            if (!\is_string($id)) {
                throw new \LogicException('Parameter "slimfony.middleware" must be a list of service ids.');
            }
            $container->getDefinition($id)->setPublic(true);
        }

        $container->compile();
        $this->container = $container;
        $this->matcher = new CompiledUrlMatcher((new CompiledUrlMatcherDumper($routes))->getCompiledRoutes(), new RequestContext());

        $chain = $this->dispatch(...);
        foreach (array_reverse($middlewareIds) as $id) {
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
            return $this->failure($request, $e);
        }
    }

    private function dispatch(Request $request): Response
    {
        $requestId = self::requestId($request);

        try {
            $this->matcher->getContext()->fromRequest($request);
            $controllerId = null;
            foreach ($this->matcher->matchRequest($request) as $key => $value) {
                if ('_controller' === $key) {
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
            if (!$response instanceof Response) {
                throw new \LogicException('Controller must return a Response.');
            }

            return $response;
        } catch (ResourceNotFoundException) {
            return Problem::response(404, $requestId);
        } catch (MethodNotAllowedException $e) {
            return Problem::response(405, $requestId, headers: ['Allow' => implode(', ', $e->getAllowedMethods())]);
        } catch (HttpException $e) {
            return Problem::response($e->status, $requestId, $e->detail, $e->headers);
        } catch (\Throwable $e) {
            return $this->failure($request, $e);
        }
    }

    private function failure(Request $request, \Throwable $e): Response
    {
        $this->logger?->error('Unhandled exception', ['exception' => $e, 'request_id' => self::requestId($request)]);

        return Problem::response(500, self::requestId($request), $this->debug ? $e::class . ': ' . $e->getMessage() : '');
    }

    private static function requestId(Request $request): ?string
    {
        $id = $request->attributes->get('request_id');

        return \is_string($id) ? $id : null;
    }

    /**
     * @return \Closure(object): void
     */
    private static function load(string $file): \Closure
    {
        $loaded = require $file;
        if (!$loaded instanceof \Closure) {
            throw new \LogicException(\sprintf('"%s" must return a Closure.', $file));
        }

        return $loaded;
    }
}
