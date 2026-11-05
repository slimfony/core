<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth\Jwt;

use Slimfony\Core\Exception\ConfigurationException;

/**
 * What a token must satisfy to be accepted. Everything here is checked when the object is built (at boot).
 *
 * Only asymmetric algorithms are allowed: a shared-secret algorithm (HS256) or "none" would let anybody who knows the
 * public key forge tokens (algorithm confusion), so they cannot even be configured.
 *
 * @api
 */
final readonly class JwtConfiguration
{
    public const array ALLOWED_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EdDSA'];

    /**
     * @param string       $issuer         exact value of the `iss` claim
     * @param string       $audience       must be (one of) the `aud` claim
     * @param list<string> $algorithms     accepted signature algorithms
     * @param int          $leewaySeconds  tolerated clock difference for `exp` and `nbf`, 0 to 300
     */
    public function __construct(
        public string $issuer,
        public string $audience,
        public array $algorithms = ['RS256', 'ES256', 'EdDSA'],
        public int $leewaySeconds = 30,
    ) {
        if ('' === $issuer || '' === $audience) {
            throw new ConfigurationException('A JWT configuration needs an issuer and an audience.');
        }
        if ([] === $algorithms) {
            throw new ConfigurationException('At least one JWT signature algorithm must be allowed.');
        }
        foreach ($algorithms as $algorithm) {
            if (!\in_array($algorithm, self::ALLOWED_ALGORITHMS, true)) {
                throw new ConfigurationException(\sprintf('JWT algorithm "%s" is not allowed; use one of: %s.', $algorithm, implode(', ', self::ALLOWED_ALGORITHMS)));
            }
        }
        if ($leewaySeconds < 0 || $leewaySeconds > 300) {
            throw new ConfigurationException('The JWT leeway must be between 0 and 300 seconds.');
        }
    }
}
