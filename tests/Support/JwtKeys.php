<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** A freshly generated signing key pair for one JWT algorithm: signs tokens and hands out the verification side. */
final readonly class JwtKeys
{
    /**
     * @param array<string, string> $jwk public key as a JWK (without kid and alg)
     */
    private function __construct(
        public string $algorithm,
        public string $keyId,
        public string $signingKey,
        public Key $verificationKey,
        public array $jwk,
        public string $publicPem,
    ) {}

    public static function generate(string $algorithm, string $keyId = 'k1'): self
    {
        if ('EdDSA' === $algorithm) {
            $pair = sodium_crypto_sign_keypair();
            $public = sodium_crypto_sign_publickey($pair);

            return new self($algorithm, $keyId, base64_encode(sodium_crypto_sign_secretkey($pair)), new Key(base64_encode($public), $algorithm), ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => JWT::urlsafeB64Encode($public)], '');
        }

        $rsa = str_starts_with($algorithm, 'RS') || str_starts_with($algorithm, 'PS');
        $resource = $rsa
            ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA])
            : openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        if (false === $resource || !openssl_pkey_export($resource, $private) || !\is_string($private)) {
            throw new \RuntimeException('OpenSSL could not generate a key pair (is OPENSSL_CONF pointing to an existing file?): ' . (string) openssl_error_string());
        }
        $details = openssl_pkey_get_details($resource);
        if (false === $details || !\is_string($details['key'])) {
            throw new \RuntimeException('OpenSSL could not export the public key.');
        }

        $jwk = $rsa
            ? ['kty' => 'RSA', 'n' => self::part($details['rsa'] ?? null, 'n'), 'e' => self::part($details['rsa'] ?? null, 'e')]
            : ['kty' => 'EC', 'crv' => 'P-256', 'x' => self::part($details['ec'] ?? null, 'x', 32), 'y' => self::part($details['ec'] ?? null, 'y', 32)];

        return new self($algorithm, $keyId, $private, new Key($details['key'], $algorithm), $jwk, $details['key']);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function token(array $claims, ?string $keyId = null, bool $withKeyId = true): string
    {
        return JWT::encode($claims, $this->signingKey, $this->algorithm, $withKeyId ? ($keyId ?? $this->keyId) : null);
    }

    /** OpenSSL drops leading zero bytes of a coordinate; a JWK needs the full length ($pad), or about 1 key in 60 is invalid */
    private static function part(mixed $group, string $name, int $pad = 0): string
    {
        return \is_array($group) && \is_string($group[$name] ?? null)
            ? JWT::urlsafeB64Encode(str_pad($group[$name], $pad, "\0", \STR_PAD_LEFT))
            : throw new \RuntimeException(\sprintf('OpenSSL key details lack "%s".', $name));
    }
}
