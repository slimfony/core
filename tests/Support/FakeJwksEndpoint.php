<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

/** A JWKS endpoint without a network: counts requests, serves a document, can be switched off. Pass `$endpoint(...)` as the fetcher. */
final class FakeJwksEndpoint
{
    public int $requests = 0;
    public bool $down = false;

    /**
     * @param list<JwtKeys> $published the keys currently in the JWKS document
     */
    public function __construct(public array $published) {}

    public function __invoke(string $uri): string
    {
        ++$this->requests;
        if ($this->down) {
            throw new \RuntimeException('provider down');
        }

        return (string) json_encode(['keys' => array_map(static fn(JwtKeys $key): array => ['kid' => $key->keyId] + $key->jwk, $this->published)]);
    }
}
