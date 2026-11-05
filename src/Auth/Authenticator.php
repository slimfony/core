<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Establishes who is calling. The core only defines the contract; implementations (for example JWT verification in
 * `slimfony/auth-jwt`, or an identity header set by a trusted gateway) live outside the core.
 *
 * @api
 */
interface Authenticator
{
    /**
     * Returns the caller, or null when credentials are missing or invalid: AuthMiddleware then answers 401.
     * Bad credentials must not throw; only an infrastructure failure may (it becomes a 500).
     */
    public function authenticate(Request $request): ?Principal;
}
