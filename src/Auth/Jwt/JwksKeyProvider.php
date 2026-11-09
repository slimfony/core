<?php

declare(strict_types=1);

namespace Slimfony\Core\Auth\Jwt;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Exception\ConfigurationException;

/**
 * Keys from the identity provider's JWKS endpoint.
 *
 * - The JWKS document is shared between all worker threads in APCu; each worker keeps the parsed keys in memory, so a
 *   request never parses a key or touches the network.
 * - A key id that is not known triggers a refetch (the provider rotated its keys), at most once per
 *   $minRefetchSeconds, so an attacker sending random key ids cannot make the service hammer the provider.
 * - A worker that starts with nothing while another thread is fetching the first document waits for it (up to
 *   $coldStartWaitSeconds) instead of failing its first requests. warmUp() does this at boot.
 * - If the provider is unreachable the keys already loaded keep working (stale if error).
 * - Only https endpoints, a 3 second timeout and a 256 KiB size limit by default.
 *
 * @api
 */
final class JwksKeyProvider implements KeyProvider
{
    private const int MAX_BYTES = 262_144;
    private const array DEFAULT_ALGORITHM_BY_KEY_TYPE = ['RSA' => 'RS256', 'EC' => 'ES256', 'OKP' => 'EdDSA'];

    /** @var \Closure(string): string */
    private readonly \Closure $fetch;
    /** @var \Closure(): float */
    private readonly \Closure $now;
    /** @var array<string, Key> parsed keys of this worker */
    private array $keys = [];
    private float $loadedAt = 0.0;
    private float $lastRefetchAt = 0.0;

    /**
     * @param (\Closure(string): string)|null $fetch returns the JWKS JSON document of a URI; defaults to a bounded HTTPS GET
     * @param (\Closure(): float)|null        $now   seconds; defaults to microtime(true)
     */
    public function __construct(
        private readonly string $jwksUri,
        private readonly ?LoggerInterface $logger = null,
        private int $ttlSeconds = 600 { set => $value >= 1 ? $value : throw new \InvalidArgumentException('ttlSeconds must be at least 1.'); },
        private int $minRefetchSeconds = 30 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('minRefetchSeconds must not be negative.'); },
        private readonly string $cachePrefix = 'slimfony_jwks:',
        private float $coldStartWaitSeconds = 3.0 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('coldStartWaitSeconds must not be negative.'); },
        ?\Closure $fetch = null,
        ?\Closure $now = null,
    ) {
        if (!str_starts_with($jwksUri, 'https://')) {
            throw new ConfigurationException('The JWKS URI must use https.');
        }
        if (!\extension_loaded('apcu') || !filter_var(\ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN)) {
            throw new ConfigurationException('JwksKeyProvider shares the keys through APCu: enable the apcu extension (apc.enabled=1).');
        }
        $this->fetch = $fetch ?? self::download(...);
        $this->now = $now ?? static fn(): float => microtime(true);
    }

    /**
     * Loads the keys now (at worker start), so the first request does not wait for the provider.
     */
    public function warmUp(): void
    {
        if ([] === $this->keys) {
            $this->load(($this->now)(), false);
        }
    }

    #[\Override]
    public function keyFor(string $keyId): ?Key
    {
        $now = ($this->now)();

        if ([] === $this->keys || $now - $this->loadedAt >= $this->ttlSeconds) {
            $this->load($now, false);
        } elseif (!isset($this->keys[$keyId]) && $now - $this->lastRefetchAt >= $this->minRefetchSeconds) {
            $this->load($now, true); // unknown key id: the provider may have rotated its keys
        }

        return $this->keys[$keyId] ?? null;
    }

    private function load(float $now, bool $force): void
    {
        $this->lastRefetchAt = $now;
        $shared = apcu_fetch($this->cachePrefix . 'doc');
        $fresh = \is_array($shared) && \is_string($shared['json'] ?? null) && \is_float($shared['at'] ?? null)
            && $now - $shared['at'] < ($force ? $this->minRefetchSeconds : $this->ttlSeconds);

        if ($fresh) {
            /** @var array{json: string, at: float} $shared */
            $this->adopt($shared['json'], $now);

            return;
        }

        // one worker fetches, the others keep using what they have (no thundering herd on the provider)
        if (apcu_add($this->cachePrefix . 'lock', 1, 10)) {
            try {
                $json = ($this->fetch)($this->jwksUri);
                $this->adopt($json, $now);
                apcu_store($this->cachePrefix . 'doc', ['json' => $json, 'at' => $now]);
            } catch (\Throwable $e) {
                $this->logger?->warning('JWKS refresh failed, keeping the keys already loaded: {error}', ['error' => $e::class]);
                if ([] === $this->keys && \is_array($shared) && \is_string($shared['json'] ?? null)) {
                    $this->adopt($shared['json'], $now); // an expired document is better than none
                }
            } finally {
                apcu_delete($this->cachePrefix . 'lock');
            }
        } elseif ([] === $this->keys) {
            $this->adoptWhenPublished($shared, $now);
        }
    }

    /**
     * Another thread is fetching and this one has nothing: wait for the document (bounded), or take an expired one.
     */
    private function adoptWhenPublished(mixed $shared, float $start): void
    {
        while (!(\is_array($shared) && \is_string($shared['json'] ?? null)) && ($this->now)() - $start < $this->coldStartWaitSeconds) {
            usleep(20_000);
            $shared = apcu_fetch($this->cachePrefix . 'doc');
        }
        if (\is_array($shared) && \is_string($shared['json'] ?? null)) {
            $this->adopt($shared['json'], $start);
        }
    }

    private function adopt(string $json, float $now): void
    {
        $document = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        $keys = \is_array($document) && \is_array($document['keys'] ?? null) ? $document['keys'] : [];

        $usable = [];
        foreach ($keys as $jwk) {
            if (!\is_array($jwk) || !\is_string($jwk['kid'] ?? null) || 'enc' === ($jwk['use'] ?? null)) {
                continue; // a key we cannot address, or one meant for encryption
            }
            $type = $jwk['kty'] ?? null;
            if (!isset($jwk['alg']) && \is_string($type) && isset(self::DEFAULT_ALGORITHM_BY_KEY_TYPE[$type])) {
                $jwk['alg'] = self::DEFAULT_ALGORITHM_BY_KEY_TYPE[$type];
            }
            $usable[] = $jwk;
        }

        $this->keys = array_map(PreparedKey::of(...), JWK::parseKeySet(['keys' => $usable]));
        $this->loadedAt = $now;
    }

    private static function download(string $uri): string
    {
        $context = stream_context_create(['http' => ['timeout' => 3, 'follow_location' => 0], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = file_get_contents($uri, false, $context, 0, self::MAX_BYTES);

        return false === $body ? throw new \RuntimeException('JWKS download failed.') : $body;
    }
}
