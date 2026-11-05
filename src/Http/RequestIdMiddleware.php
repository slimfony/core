<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Slimfony\Core\Log\RequestContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets request attributes "request_id" and "trace_id" (never trusting raw input) and echoes X-Request-Id.
 * Incoming values are accepted only in a strict format, otherwise they are replaced (log injection guard).
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?RequestContext $context = null) {}

    public function process(Request $request, \Closure $next): Response
    {
        $id = $request->headers->get('X-Request-Id');
        $requestId = \is_string($id) && 1 === preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $id) ? $id : bin2hex(random_bytes(16));

        $parent = $request->headers->get('traceparent');
        $traceId = \is_string($parent) && 1 === preg_match('/^00-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/D', $parent, $m)
            && '00000000000000000000000000000000' !== $m[1]
            ? $m[1]
            : bin2hex(random_bytes(16));

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('trace_id', $traceId);
        if (null !== $this->context) { // lets the logger tag every line of this request
            $this->context->requestId = $requestId;
            $this->context->traceId = $traceId;
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
