<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth\Jwt;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Principal;
use Slimfony\Core\Exception\ConfigurationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies `Authorization: Bearer <JWT>` (a resource server: it never issues tokens). Needs `firebase/php-jwt`.
 *
 * A token is accepted only if all of these hold: the algorithm is on the allow-list and matches the key's algorithm,
 * the signature verifies with the key named by `kid`, `iss` equals the configured issuer, the audience is among `aud`,
 * `sub` is present, and `exp` is present and not past (`nbf` not in the future), with the configured leeway.
 * Any failure returns null (the middleware answers 401); the reason is never sent to the caller.
 * Scopes come from the space separated `scope` claim and/or the `scp` array.
 *
 * Speed: a signature check is the most expensive thing a request does. A token that verified successfully is
 * remembered (by its hash) for `$cacheSeconds`, never beyond its own `exp`, so repeated calls with the same token
 * cost a hash and a lookup. The price: a token revoked at the identity provider stays usable for at most that long.
 * Set it to 0 to verify every request. Only successes are cached, so invalid tokens cannot fill the cache.
 *
 * @api
 */
final class JwtAuthenticator implements Authenticator
{
    private const int MAX_TOKEN_BYTES = 8192;
    private const int MAX_REMEMBERED = 1000;

    /** @var array<string, array{Principal, float}> verified tokens by the raw sha256 of the token: principal, reusable until */
    private array $verified = [];
    /** @var \Closure(): float */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): float)|null $now seconds; defaults to microtime(true)
     */
    public function __construct(
        private readonly JwtConfiguration $configuration,
        private readonly KeyProvider $keys,
        private readonly ?LoggerInterface $logger = null,
        private int $cacheSeconds = 30 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('cacheSeconds must not be negative.'); },
        ?\Closure $now = null,
    ) {
        if (!class_exists(JWT::class)) {
            throw new ConfigurationException('JwtAuthenticator needs the firebase/php-jwt package: composer require firebase/php-jwt');
        }
        $this->now = $now ?? static fn(): float => microtime(true);
    }

    #[\Override]
    public function authenticate(Request $request): ?Principal
    {
        $header = $request->headers->get('Authorization');
        if (!\is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = substr($header, 7);
        if (\strlen($token) > self::MAX_TOKEN_BYTES || 2 !== substr_count($token, '.')) {
            return null;
        }

        $now = ($this->now)();
        $digest = $this->cacheSeconds > 0 ? hash('sha256', $token, true) : '';
        if ('' !== $digest && isset($this->verified[$digest]) && $this->verified[$digest][1] > $now) {
            return $this->verified[$digest][0];
        }

        try {
            $claims = $this->verify($token);
        } catch (\Throwable $e) {
            $this->logger?->debug('JWT rejected: {reason}', ['reason' => $e::class]);

            return null;
        }
        if (null === $claims) {
            return null;
        }

        $principal = $this->principal($claims);
        if (null !== $principal && '' !== $digest) {
            $this->remember($digest, $principal, $now, $claims['exp'] ?? 0);
        }

        return $principal;
    }

    /**
     * @return array<string, mixed>|null the verified claims, null when the header or key do not fit
     */
    private function verify(string $token): ?array
    {
        $head = json_decode(JWT::urlsafeB64Decode(explode('.', $token, 2)[0]), true, 4, \JSON_THROW_ON_ERROR);
        $algorithm = \is_array($head) ? ($head['alg'] ?? null) : null;
        $keyId = \is_array($head) ? ($head['kid'] ?? null) : null;
        if (!\is_string($algorithm) || !\in_array($algorithm, $this->configuration->algorithms, true) || !\is_string($keyId) || '' === $keyId) {
            return null;
        }

        $key = $this->keys->keyFor($keyId);
        if (null === $key || $key->getAlgorithm() !== $algorithm) {
            return null;
        }

        return $this->decode($token, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token, Key $key): array
    {
        $previous = JWT::$leeway; // the library keeps it in a static: set for this call only
        JWT::$leeway = $this->configuration->leewaySeconds;
        try {
            $payload = JWT::decode($token, $key);
        } finally {
            JWT::$leeway = $previous;
        }

        $claims = json_decode((string) json_encode($payload), true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($claims)) {
            throw new \UnexpectedValueException('Claims are not an object.');
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    private function remember(string $digest, Principal $principal, float $now, mixed $expires): void
    {
        if (\count($this->verified) >= self::MAX_REMEMBERED) {
            unset($this->verified[array_key_first($this->verified)]); // oldest first
        }
        $this->verified[$digest] = [$principal, min($now + $this->cacheSeconds, \is_int($expires) || \is_float($expires) ? (float) $expires : 0.0)];
    }

    private function audienceMatches(mixed $audience): bool
    {
        return \is_string($audience)
            ? $audience === $this->configuration->audience
            : \is_array($audience) && \in_array($this->configuration->audience, $audience, true);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function principal(array $claims): ?Principal
    {
        $subject = $claims['sub'] ?? null;
        $expires = $claims['exp'] ?? null;
        if (
            ($claims['iss'] ?? null) !== $this->configuration->issuer
            || !\is_string($subject) || '' === $subject
            || !(\is_int($expires) || \is_float($expires))
            || !$this->audienceMatches($claims['aud'] ?? null)
        ) {
            $this->logger?->debug('JWT rejected: claims do not match the configuration');

            return null;
        }

        $scopes = [];
        if (\is_string($claims['scope'] ?? null)) {
            $parts = preg_split('/\s+/', trim($claims['scope']), -1, \PREG_SPLIT_NO_EMPTY);
            $scopes = false === $parts ? [] : $parts;
        }
        if (\is_array($claims['scp'] ?? null)) {
            foreach ($claims['scp'] as $scope) {
                if (\is_string($scope) && '' !== $scope) {
                    $scopes[] = $scope;
                }
            }
        }

        return new Principal($subject, array_values(array_unique($scopes)), $claims);
    }
}
