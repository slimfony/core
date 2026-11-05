<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Principal;
use Symfony\Component\HttpFoundation\Request;

/** Test double: maps bearer tokens to principals. */
final class FakeAuthenticator implements Authenticator
{
    public int $calls = 0;

    /**
     * @param array<string, Principal> $tokens
     */
    public function __construct(private readonly array $tokens) {}

    #[\Override]
    public function authenticate(Request $request): ?Principal
    {
        ++$this->calls;
        $header = $request->headers->get('Authorization');

        return \is_string($header) && str_starts_with($header, 'Bearer ') ? ($this->tokens[substr($header, 7)] ?? null) : null;
    }
}
