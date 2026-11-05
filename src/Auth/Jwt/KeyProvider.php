<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth\Jwt;

use Firebase\JWT\Key;

/**
 * Where the public keys for verifying tokens come from.
 *
 * @api
 */
interface KeyProvider
{
    /**
     * The verification key for the token's `kid` header, or null when there is none.
     * Must be cheap: it is called for every request.
     */
    public function keyFor(string $keyId): ?Key;
}
