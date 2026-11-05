<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\MiddlewareInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * http_requests_total{method,route,status} and http_request_duration_seconds{method,route}.
 *
 * The route label is the route NAME, never the raw URL (cardinality protection); requests that matched no route
 * are "unmatched", unusual methods are "OTHER". Put it first in the chain so it measures the whole request.
 *
 * @api
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    private const array METHODS = ['GET' => true, 'HEAD' => true, 'POST' => true, 'PUT' => true, 'PATCH' => true, 'DELETE' => true, 'OPTIONS' => true];

    private readonly Counter $requests;
    private readonly Histogram $duration;

    public function __construct(MetricsRegistry $registry)
    {
        $this->requests = $registry->counter('http_requests_total', 'Total HTTP requests.', ['method', 'route', 'status']);
        $this->duration = $registry->histogram('http_request_duration_seconds', 'HTTP request duration in seconds.', ['method', 'route']);
    }

    #[\Override]
    public function process(Request $request, \Closure $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);
        $seconds = (hrtime(true) - $start) / 1_000_000_000;

        $method = $request->getMethod();
        $method = isset(self::METHODS[$method]) ? $method : 'OTHER';
        $route = $request->attributes->get(Attributes::ROUTE);
        $route = \is_string($route) ? $route : 'unmatched';

        $this->requests->inc([$method, $route, (string) $response->getStatusCode()]);
        $this->duration->observe($seconds, [$method, $route]);

        return $response;
    }
}
