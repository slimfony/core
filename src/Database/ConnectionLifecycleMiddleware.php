<?php

declare(strict_types=1);

namespace Slimfony\Core\Database;

use Slimfony\Core\Http\MiddlewareInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Calls ConnectionLifecycle::beginRequest() (idle-connection check) at the start of each request.
 */
final class ConnectionLifecycleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ConnectionLifecycle $lifecycle) {}

    public function process(Request $request, \Closure $next): Response
    {
        $this->lifecycle->beginRequest();

        return $next($request);
    }
}
