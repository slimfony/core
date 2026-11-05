<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth\Jwt;

use Firebase\JWT\Key;

/**
 * A fixed set of keys, for example a public key distributed with the deployment (a PEM from a secret file).
 *
 * @api
 */
final class StaticKeyProvider implements KeyProvider
{
    /** @var array<string, Key> */
    private readonly array $keys;

    /**
     * @param array<string, Key> $keys by key id
     */
    public function __construct(array $keys)
    {
        $this->keys = array_map(PreparedKey::of(...), $keys);
    }

    #[\Override]
    public function keyFor(string $keyId): ?Key
    {
        return $this->keys[$keyId] ?? null;
    }
}
