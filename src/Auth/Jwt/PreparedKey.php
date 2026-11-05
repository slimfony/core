<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth\Jwt;

use Firebase\JWT\Key;
use Slimfony\Core\Exception\ConfigurationException;

/**
 * Parses a PEM verification key once, when it is loaded, instead of on every request: the signature library would
 * otherwise parse the same PEM twice per verification. Pure function, no state.
 *
 * @internal
 */
final class PreparedKey
{
    private function __construct() {}

    public static function of(Key $key): Key
    {
        $material = $key->getKeyMaterial();
        $algorithm = $key->getAlgorithm();
        if (!\is_string($material) || !(str_starts_with($algorithm, 'RS') || str_starts_with($algorithm, 'ES'))) {
            return $key; // EdDSA keys are raw bytes, already cheap
        }

        $parsed = openssl_pkey_get_public($material);

        return false === $parsed ? throw new ConfigurationException('A JWT verification key could not be parsed.') : new Key($parsed, $algorithm);
    }
}
