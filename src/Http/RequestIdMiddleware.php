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

    #[\Override]
    public function process(Request $request, \Closure $next): Response
    {
        $id = $request->headers->get('X-Request-Id');
        $requestId = \is_string($id) && 1 === preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $id) ? $id : bin2hex(random_bytes(16));

        $parent = $request->headers->get('traceparent');
        $traceId = \is_string($parent) && 1 === preg_match('/^00-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/D', $parent, $m)
            && '00000000000000000000000000000000' !== $m[1]
            ? $m[1]
            : bin2hex(random_bytes(16));

        $request->attributes->set(Attributes::REQUEST_ID, $requestId);
        $request->attributes->set(Attributes::TRACE_ID, $traceId);
        $this->context?->start($requestId, $traceId); // lets the logger tag every line of this request

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
