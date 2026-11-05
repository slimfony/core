<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Firebase\JWT\Key;
use Slimfony\Core\Auth\Jwt\KeyProvider;

/** Test double: counts how often the verification key was asked for, i.e. how often a signature was really verified. */
final class CountingKeyProvider implements KeyProvider
{
    public int $lookups = 0;

    public function __construct(private readonly KeyProvider $inner) {}

    #[\Override]
    public function keyFor(string $keyId): ?Key
    {
        ++$this->lookups;

        return $this->inner->keyFor($keyId);
    }
}
