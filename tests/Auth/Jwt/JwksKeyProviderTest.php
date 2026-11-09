<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Auth\Jwt;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Jwt\JwksKeyProvider;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Tests\Support\FakeJwksEndpoint;
use Slimfony\Core\Tests\Support\JwtKeys;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TestClock;

final class JwksKeyProviderTest extends TestCase
{
    private const string URI = 'https://idp.example.test/.well-known/jwks.json';

    private string $prefix = '';

    protected function setUp(): void
    {
        if (!\extension_loaded('apcu') || !filter_var(\ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN) || !filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOLEAN)) {
            self::markTestSkipped('Needs APCu with apc.enable_cli=1 (CI always has it).');
        }
        $this->prefix = 'slimfony_jwks_test_' . bin2hex(random_bytes(4)) . ':';
    }

    protected function tearDown(): void
    {
        if ('' !== $this->prefix) {
            apcu_delete(new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/'));
        }
    }

    public function testKeysAreFetchedOnceAndTheyVerifyTokens(): void
    {
        $keys = JwtKeys::generate('ES256');
        $endpoint = new FakeJwksEndpoint([$keys]);
        $provider = new JwksKeyProvider(self::URI, cachePrefix: $this->prefix, fetch: $endpoint(...));

        $key = $provider->keyFor('k1');
        $provider->keyFor('k1');
        $provider->keyFor('k1');

        self::assertNotNull($key);
        self::assertSame(1, $endpoint->requests, 'the provider must not be asked on every request');
        self::assertSame('alice', JWT::decode($keys->token(['sub' => 'alice', 'exp' => time() + 60]), $key)->sub);
    }

    public function testARotatedKeyIsPickedUpButUnknownKeyIdsCannotMakeTheServiceHammerTheProvider(): void
    {
        $old = JwtKeys::generate('ES256', 'old');
        $new = JwtKeys::generate('ES256', 'new');
        $endpoint = new FakeJwksEndpoint([$old]);
        $clock = new TestClock(1000.0);
        $provider = new JwksKeyProvider(self::URI, minRefetchSeconds: 30, cachePrefix: $this->prefix, fetch: $endpoint(...), now: $clock(...));

        self::assertNotNull($provider->keyFor('old'));
        $endpoint->published = [$old, $new]; // the provider rotates

        $clock->now = 1001.0;
        self::assertNull($provider->keyFor('new'), 'too soon to ask the provider again');
        self::assertSame(1, $endpoint->requests);

        $clock->now = 1040.0;
        self::assertNotNull($provider->keyFor('new'));
        self::assertSame(2, $endpoint->requests);

        for ($i = 0; $i < 50; ++$i) { // an attacker cycling through random key ids
            $provider->keyFor('random-' . $i);
        }
        self::assertSame(2, $endpoint->requests, 'unknown key ids inside the refetch interval cost nothing');

        $clock->now = 1080.0; // interval over: one refetch, however many unknown key ids arrive
        for ($i = 0; $i < 50; ++$i) {
            $provider->keyFor('random-' . $i);
        }
        self::assertSame(3, $endpoint->requests, 'one refetch per interval, not one per unknown key id');
    }

    public function testKeysKeepWorkingWhenTheProviderIsDown(): void
    {
        $endpoint = new FakeJwksEndpoint([JwtKeys::generate('ES256')]);
        $clock = new TestClock(1000.0);
        $logger = new RecordingLogger();
        $provider = new JwksKeyProvider(self::URI, $logger, ttlSeconds: 600, cachePrefix: $this->prefix, fetch: $endpoint(...), now: $clock(...));

        self::assertNotNull($provider->keyFor('k1'));

        $endpoint->down = true;
        $clock->now = 1000.0 + 601; // the cached document has expired and the refresh fails
        self::assertNotNull($provider->keyFor('k1'), 'stale keys are better than rejecting every caller');
        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('warning: JWKS refresh failed', $logger->records[0]);
    }

    public function testNothingLoadedAndProviderDownGivesNoKeyInsteadOfAnException(): void
    {
        $endpoint = new FakeJwksEndpoint([]);
        $endpoint->down = true;
        $provider = new JwksKeyProvider(self::URI, cachePrefix: $this->prefix, fetch: $endpoint(...));

        self::assertNull($provider->keyFor('k1'));
    }

    public function testOnlyHttpsEndpointsAreAccepted(): void
    {
        $this->expectException(ConfigurationException::class);

        new JwksKeyProvider('http://idp.example.test/jwks.json', cachePrefix: $this->prefix);
    }
    public function testAWorkerThatStartsWhileAnotherThreadIsFetchingWaitsForTheDocumentInsteadOfFailing(): void
    {
        $keys = JwtKeys::generate('ES256');
        $endpoint = new FakeJwksEndpoint([$keys]);
        apcu_add($this->prefix . 'lock', 1, 10); // the other thread is fetching
        $calls = 0;
        $prefix = $this->prefix;
        $now = static function () use (&$calls, $prefix, $endpoint): float {
            if (3 === ++$calls) { // the other thread finishes while we wait
                apcu_store($prefix . 'doc', ['json' => ($endpoint)('x'), 'at' => 1000.0]);
            }

            return 1000.0 + $calls * 0.01;
        };
        $provider = new JwksKeyProvider(self::URI, cachePrefix: $this->prefix, fetch: static fn(): string => throw new \LogicException('not the fetching thread'), now: $now);

        self::assertNotNull($provider->keyFor('k1'));
    }

    public function testWarmUpLoadsTheKeysBeforeTheFirstRequest(): void
    {
        $endpoint = new FakeJwksEndpoint([JwtKeys::generate('ES256')]);
        $provider = new JwksKeyProvider(self::URI, cachePrefix: $this->prefix, fetch: $endpoint(...));

        $provider->warmUp();
        $provider->warmUp();

        self::assertSame(1, $endpoint->requests);
        self::assertNotNull($provider->keyFor('k1'));
        self::assertSame(1, $endpoint->requests);
    }
}
