<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Auth\Jwt\StaticKeyProvider;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Observability\Keys;
use Slimfony\Core\Tests\Support\JwtKeys;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the parsers do with input nobody planned for. The generator is seeded, so a failure reproduces: the message names the case.
 */
final class FuzzTest extends TestCase
{
    private const int CASES = 400;

    private static function random(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }

    private static function junk(Randomizer $random, int $max = 40): string
    {
        $alphabet = ['|', ',', '%', ' ', '.', '-', '=', '"', "\0", "\n", 'é', '0', '9', 'a', 'Z', '{', '}', '[', ']', 'null', 'true', '1e5'];
        $out = '';
        for ($i = $random->getInt(0, $max); $i > 0; --$i) {
            $out .= $alphabet[$random->getInt(0, \count($alphabet) - 1)];
        }

        return $out;
    }

    public function testAKeyParsesBackToItsLabelsWhateverTheyContain(): void
    {
        $random = self::random(1);
        for ($case = 0; $case < self::CASES; ++$case) {
            $labels = [];
            for ($i = $random->getInt(0, 3); $i > 0; --$i) {
                $labels[] = self::junk($random, 12);
            }
            $bucket = 0 === $case % 2 ? null : $random->getInt(0, 20);

            $parsed = Keys::parse(Keys::of(Keys::BUCKET, 'metric_total', $labels, $bucket));

            self::assertNotNull($parsed, "case $case");
            self::assertSame(implode(',', array_map(rawurlencode(...), $labels)), $parsed['labels'], "case $case");
            self::assertSame($bucket, $parsed['bucket'], "case $case");
        }
    }

    public function testParsingAStorageKeyThatIsNotOursNeverFails(): void
    {
        $random = self::random(2);
        for ($case = 0; $case < self::CASES; ++$case) {
            $key = self::junk($random, 60);
            $parsed = Keys::parse($key);

            if (null !== $parsed) { // what was parsed is the start of what went in
                self::assertStringStartsWith($parsed['kind'] . '|' . $parsed['name'] . '|' . $parsed['labels'], $key, "case $case");
            }
        }
    }

    public function testEnvAnswersWithAValueOrAConfigurationErrorAndNothingElse(): void
    {
        $random = self::random(3);
        for ($case = 0; $case < self::CASES; ++$case) {
            $env = new Env(['X' => self::junk($random, 12)]);
            $raw = $env->string('X');

            try {
                $env->bool('X');
            } catch (ConfigurationException) { // refusing is an answer too
            }
            // an integer is accepted exactly when PHP's own filter accepts it
            try {
                self::assertSame(filter_var($raw, \FILTER_VALIDATE_INT), $env->int('X'), "case $case");
            } catch (ConfigurationException) {
                self::assertFalse(filter_var($raw, \FILTER_VALIDATE_INT), "case $case");
            }
        }
    }

    public function testATokenThatIsNotOneIsRefusedWithoutAnError(): void
    {
        $keys = JwtKeys::generate('ES256');
        $authenticator = new JwtAuthenticator(
            new JwtConfiguration('https://idp.example.test', 'orders-api'),
            new StaticKeyProvider([$keys->keyId => $keys->verificationKey]),
        );
        $random = self::random(4);
        $headers = [
            '{"alg":"none","kid":"k1"}', '{"alg":"HS256","kid":"k1"}', '{"alg":"ES256"}', '{"alg":["ES256"],"kid":"k1"}', '{"alg":"ES256","kid":["k1"]}',
            '{"alg":"ES256","kid":""}', '{"alg":"ES256","kid":"nope"}', '[]', 'null', '"x"', '7', '{"alg":"ES256","kid":"k1","x":' . str_repeat('[', 40) . str_repeat(']', 40) . '}',
        ];

        for ($case = 0; $case < self::CASES; ++$case) {
            $header = $headers[$case % \count($headers)];
            $token = JWT::urlsafeB64Encode($case < 100 ? $header : self::junk($random)) . '.' . JWT::urlsafeB64Encode(self::junk($random)) . '.' . JWT::urlsafeB64Encode(random_bytes(64));

            self::assertNull($authenticator->authenticate(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])), "case $case: $token");
        }
    }

    public function testASignedTokenWithOneChangedCharacterInItsClaimsIsRefused(): void
    {
        $keys = JwtKeys::generate('ES256');
        $authenticator = new JwtAuthenticator(
            new JwtConfiguration('https://idp.example.test', 'orders-api'),
            new StaticKeyProvider([$keys->keyId => $keys->verificationKey]),
        );
        $token = $keys->token(['iss' => 'https://idp.example.test', 'aud' => 'orders-api', 'sub' => 'alice', 'exp' => time() + 300]);
        self::assertNotNull($authenticator->authenticate(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token])));

        [$head, $claims, $signature] = explode('.', $token);
        $random = self::random(5);
        for ($case = 0; $case < 60; ++$case) {
            $at = $random->getInt(1, \strlen($claims) - 2); // not the last character: its low bits may be padding
            $changed = $claims;
            $changed[$at] = 'A' === $claims[$at] ? 'B' : 'A';

            self::assertNull($authenticator->authenticate(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . "$head.$changed.$signature"])), "case $case at $at");
        }
    }
}
