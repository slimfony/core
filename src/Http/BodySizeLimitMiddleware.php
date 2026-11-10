<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 413 to a body that is too large, before the application reads it.
 *
 * With a declared Content-Length this costs one comparison. Without one (a chunked body, which the web server decodes and
 * does not announce by length) the body itself is read, but never beyond the limit plus one byte, so an endless upload
 * cannot fill the memory of a worker. Requests that carry no body at all are not touched.
 *
 * @api
 */
final class BodySizeLimitMiddleware implements MiddlewareInterface
{
    private const array METHODS_WITH_A_BODY = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private int $maxBytes = 1_048_576 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('maxBytes must not be negative.'); }) {}

    #[\Override]
    public function process(Request $request, \Closure $next): Response
    {
        if ($this->tooLarge($request)) {
            $id = $request->attributes->get(Attributes::REQUEST_ID);

            return new ProblemResponse(413, \is_string($id) ? $id : null);
        }

        return $next($request);
    }

    private function tooLarge(Request $request): bool
    {
        $length = $request->headers->get('Content-Length');
        if (null !== $length) {
            return !ctype_digit($length) || (int) $length > $this->maxBytes;
        }
        if (!\in_array($request->getMethod(), self::METHODS_WITH_A_BODY, true)) {
            return false;
        }

        $body = $request->getContent(true);
        if (!\is_resource($body)) {
            return false;
        }
        $head = stream_get_contents($body, $this->maxBytes + 1);
        rewind($body); // the application reads the body from the start

        return false !== $head && \strlen($head) > $this->maxBytes;
    }
}
