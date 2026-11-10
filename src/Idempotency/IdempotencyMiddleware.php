<?php

declare(strict_types=1);

namespace Slimfony\Core\Idempotency;

use Slimfony\Core\Auth\Principal;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\MiddlewareInterface;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` for the routes that ask for it (`_idempotent: true`), on POST and PATCH (the other methods repeat safely).
 *
 * A client that did not see the answer sends the same request with the same key again, and gets the first answer back (with
 * `Idempotent-Replayed: true`) instead of the work being done twice. The same key with a different request is a mistake (422);
 * the same request while the first is still running is asked to wait (409, `Retry-After`). A server error (5xx) or an exception
 * is not kept: the request may be sent again and will run. A request without the header runs as usual.
 *
 * Keys belong to the caller: the same key of two callers are two keys. Put the middleware after AuthMiddleware. The handler
 * can read the scoped key from the request attribute `Attributes::IDEMPOTENCY_KEY` to guard its own effect (a unique column),
 * which is what makes the work safe even when the process dies between the work and the answer being kept.
 *
 * @api
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    private const string KEY_FORMAT = '/^[A-Za-z0-9._:-]{8,128}$/D';
    private const array METHODS = ['POST', 'PATCH'];

    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly int $retryAfterSeconds = 1,
    ) {}

    #[\Override]
    public function process(Request $request, \Closure $next): Response
    {
        $header = $request->headers->get('Idempotency-Key');
        if (true !== $request->attributes->get(Attributes::IDEMPOTENT) || null === $header || !\in_array($request->getMethod(), self::METHODS, true)) {
            return $next($request);
        }

        $id = $request->attributes->get(Attributes::REQUEST_ID);
        $requestId = \is_string($id) ? $id : null;
        if (1 !== preg_match(self::KEY_FORMAT, $header)) {
            return new ProblemResponse(400, $requestId, 'The Idempotency-Key must be 8 to 128 characters: letters, digits and . _ : -');
        }

        $principal = $request->attributes->get(Attributes::PRINCIPAL);
        $caller = $principal instanceof Principal ? 'sub:' . $principal->subject : 'ip:' . ($request->getClientIp() ?? '');
        $key = hash('sha256', $caller . "\n" . $header);
        $fingerprint = hash('sha256', $request->getMethod() . ' ' . $request->getPathInfo() . '?' . $request->getQueryString() . "\n" . $request->getContent());

        switch ($this->store->begin($key, $fingerprint)) {
            case Outcome::Done:
                $stored = $this->store->stored($key);
                if (null !== $stored) {
                    return new Response($stored->body, $stored->status, ['Content-Type' => $stored->contentType, 'Idempotent-Replayed' => 'true']);
                }

                return new ProblemResponse(409, $requestId, 'The first request is not finished.', ['Retry-After' => (string) $this->retryAfterSeconds]);
            case Outcome::Mismatch:
                return new ProblemResponse(422, $requestId, 'This Idempotency-Key was used with a different request.');
            case Outcome::InProgress:
                return new ProblemResponse(409, $requestId, 'The first request with this Idempotency-Key is still being served.', ['Retry-After' => (string) $this->retryAfterSeconds]);
            case Outcome::Fresh:
                break;
        }

        $request->attributes->set(Attributes::IDEMPOTENCY_KEY, $key);
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->store->release($key); // the request failed: let the client try again

            throw $e;
        }

        $body = $response->getContent();
        if ($response->getStatusCode() >= 500 || false === $body) {
            $this->store->release($key);
        } else {
            $this->store->complete($key, new StoredResponse($response->getStatusCode(), $body, $response->headers->get('Content-Type', 'application/octet-stream') ?? 'application/octet-stream'));
        }

        return $response;
    }
}
