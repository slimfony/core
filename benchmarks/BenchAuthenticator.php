<?php

declare(strict_types=1);

namespace Slimfony\Core\Benchmarks;

use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Principal;
use Symfony\Component\HttpFoundation\Request;

/** Accepts one fixed token: the benchmark measures the middleware, not a real token verification. */
final class BenchAuthenticator implements Authenticator
{
    #[\Override]
    public function authenticate(Request $request): ?Principal
    {
        return 'Bearer bench' === $request->headers->get('Authorization') ? new Principal('bench', ['bench:read']) : null;
    }
}
