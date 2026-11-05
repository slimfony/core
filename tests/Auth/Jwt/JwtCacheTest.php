<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Auth\Jwt;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Auth\Jwt\StaticKeyProvider;
use Slimfony\Core\Tests\Support\CountingKeyProvider;
use Slimfony\Core\Tests\Support\JwtKeys;
use Slimfony\Core\Tests\Support\TestClock;
use Symfony\Component\HttpFoundation\Request;

/** A token that verified once is remembered briefly, so repeated calls skip the expensive signature check. */
final class JwtCacheTest extends TestCase
{
    private const string ISSUER = 'https://idp.example.test';
    private const string AUDIENCE = 'orders-api';

    /**
     * @param array<string, mixed> $override
     */
    private static function token(JwtKeys $keys, array $override = []): string
    {
        return $keys->token(array_replace(['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'alice', 'exp' => time() + 300], $override));
    }

    private static function request(string $token): Request
    {
        return Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    private static function subject(JwtAuthenticator $authenticator, Request $request): ?string
    {
        return $authenticator->authenticate($request)?->subject;
    }

    private static function counter(JwtKeys $keys): CountingKeyProvider
    {
        return new CountingKeyProvider(new StaticKeyProvider([$keys->keyId => $keys->verificationKey]));
    }

    private static function authenticator(CountingKeyProvider $counter, TestClock $clock, int $cacheSeconds): JwtAuthenticator
    {
        return new JwtAuthenticator(new JwtConfiguration(self::ISSUER, self::AUDIENCE), $counter, cacheSeconds: $cacheSeconds, now: $clock(...));
    }

    public function testATokenIsVerifiedOnceAndRememberedOnlyForTheCacheWindow(): void
    {
        $keys = JwtKeys::generate('ES256');
        $clock = new TestClock(1000.0);
        $counter = self::counter($keys);
        $authenticator = self::authenticator($counter, $clock, 30);
        $request = self::request(self::token($keys));

        self::assertSame('alice', self::subject($authenticator, $request));
        self::assertSame('alice', self::subject($authenticator, $request));
        self::assertSame(1, $counter->lookups, 'the second call must not verify the signature again');

        $clock->now = 1031.0; // window over
        self::assertSame('alice', self::subject($authenticator, $request));
        self::assertSame(2, $counter->lookups);
    }

    public function testWithTheCacheSwitchedOffEveryRequestIsVerified(): void
    {
        $keys = JwtKeys::generate('ES256');
        $counter = self::counter($keys);
        $authenticator = self::authenticator($counter, new TestClock(), 0);
        $request = self::request(self::token($keys));

        $authenticator->authenticate($request);
        $authenticator->authenticate($request);

        self::assertSame(2, $counter->lookups);
    }

    public function testAnotherTokenIsNeverServedFromSomeoneElsesCacheEntry(): void
    {
        $keys = JwtKeys::generate('ES256');
        $counter = self::counter($keys);
        $authenticator = self::authenticator($counter, new TestClock(), 30);

        self::assertSame('alice', self::subject($authenticator, self::request(self::token($keys))));
        self::assertSame('bob', self::subject($authenticator, self::request(self::token($keys, ['sub' => 'bob']))));
        self::assertSame(2, $counter->lookups, 'a different token is verified on its own');
    }

    public function testRefusedTokensAreNeverRemembered(): void
    {
        $keys = JwtKeys::generate('ES256');
        $counter = self::counter($keys);
        $authenticator = self::authenticator($counter, new TestClock(), 30);
        $wrongAudience = self::request(self::token($keys, ['aud' => 'billing-api']));

        self::assertNull($authenticator->authenticate($wrongAudience));
        self::assertNull($authenticator->authenticate($wrongAudience));
        self::assertSame(2, $counter->lookups, 'failures are checked again every time, so garbage cannot fill the cache');
    }
}
