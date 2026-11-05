<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Psr\Log\LoggerInterface;
use Slimfony\Core\Exception\HttpException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Turns any Throwable into a problem+json response. Anything unexpected becomes a 500 without internals
 * (they go to the log); only in debug mode the class and message are shown.
 *
 * @internal
 */
final class ErrorResponder
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function respond(Request $request, \Throwable $e): Response
    {
        $id = $request->attributes->get(Attributes::REQUEST_ID);
        $requestId = \is_string($id) ? $id : null;

        return match (true) {
            $e instanceof ResourceNotFoundException => new ProblemResponse(404, $requestId),
            $e instanceof MethodNotAllowedException => new ProblemResponse(405, $requestId, headers: ['Allow' => implode(', ', $e->getAllowedMethods())]),
            $e instanceof HttpException => new ProblemResponse($e->status, $requestId, $e->detail, $e->headers),
            $e instanceof SuspiciousOperationException => new ProblemResponse(400, $requestId), // e.g. a Host outside TRUSTED_HOSTS
            default => $this->unexpected($requestId, $e),
        };
    }

    private function unexpected(?string $requestId, \Throwable $e): Response
    {
        $this->logger?->error('Unhandled exception', ['exception' => $e, 'request_id' => $requestId]);

        return new ProblemResponse(500, $requestId, $this->debug ? $e::class . ': ' . $e->getMessage() : '');
    }
}
