<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth;

use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\MiddlewareInterface;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny by default: once this middleware is in the chain, every route needs an authenticated caller unless it
 * says `_public: true`. Routes can also demand scopes with `_scopes: ['orders:read']` (all of them, else 403).
 *
 * A request that matches no route is answered 401 too, so anonymous callers cannot map the API by its 404s.
 * Health and metrics routes are normally `_public` (the metrics controller has its own protection).
 *
 * @api
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Authenticator $authenticator) {}

    #[\Override]
    public function process(Request $request, \Closure $next): Response
    {
        if (true === $request->attributes->get(Attributes::PUBLIC)) {
            return $next($request);
        }

        $id = $request->attributes->get(Attributes::REQUEST_ID);
        $requestId = \is_string($id) ? $id : null;

        $principal = $this->authenticator->authenticate($request);
        if (null === $principal) {
            return new ProblemResponse(401, $requestId, headers: ['WWW-Authenticate' => 'Bearer']);
        }

        $required = $request->attributes->get(Attributes::SCOPES);
        foreach (\is_array($required) ? $required : [] as $scope) {
            if (!\is_string($scope) || !$principal->hasScope($scope)) {
                return new ProblemResponse(403, $requestId);
            }
        }

        $request->attributes->set(Attributes::PRINCIPAL, $principal);

        return $next($request);
    }
}
