<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cheap 413 on a declared Content-Length. Bodies without Content-Length (chunked) are not covered here:
 * enforce those at the edge (Caddy `request_body { max_size ... }`).
 */
final class BodySizeLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private int $maxBytes = 1_048_576 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('maxBytes must not be negative.'); }) {}

    #[\Override]
    public function process(Request $request, \Closure $next): Response
    {
        $length = $request->headers->get('Content-Length');
        if (null !== $length && (!ctype_digit($length) || (int) $length > $this->maxBytes)) {
            $id = $request->attributes->get(Attributes::REQUEST_ID);

            return new ProblemResponse(413, \is_string($id) ? $id : null);
        }

        return $next($request);
    }
}
