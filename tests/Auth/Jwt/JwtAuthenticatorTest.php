<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Auth\Jwt;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Auth\Jwt\StaticKeyProvider;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Support\JwtKeys;
use Slimfony\Core\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;

final class JwtAuthenticatorTest extends TestCase
{
    private const string ISSUER = 'https://idp.example.test';
    private const string AUDIENCE = 'orders-api';

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function claims(array $override = []): array
    {
        return array_replace(['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => 'alice', 'exp' => time() + 300, 'scope' => 'orders:read orders:write'], $override);
    }

    private static function authenticator(JwtKeys $keys, ?JwtConfiguration $configuration = null): JwtAuthenticator
    {
        return new JwtAuthenticator(
            $configuration ?? new JwtConfiguration(self::ISSUER, self::AUDIENCE),
            new StaticKeyProvider([$keys->keyId => $keys->verificationKey]),
        );
    }

    private static function bearer(string $token): Request
    {
        return Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    #[DataProvider('algorithms')]
    public function testAValidTokenBecomesAPrincipalWithSubjectScopesAndClaims(string $algorithm): void
    {
        $keys = JwtKeys::generate($algorithm);

        $principal = self::authenticator($keys, new JwtConfiguration(self::ISSUER, self::AUDIENCE, [$algorithm]))->authenticate(self::bearer($keys->token(self::claims())));

        self::assertNotNull($principal);
        self::assertSame('alice', $principal->subject);
        self::assertSame(['orders:read', 'orders:write'], $principal->scopes);
        self::assertSame(self::ISSUER, $principal->claims['iss']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function algorithms(): iterable
    {
        yield 'RS256' => ['RS256'];
        yield 'ES256' => ['ES256'];
        yield 'EdDSA' => ['EdDSA'];
    }

    public function testScopesAlsoComeFromAScpArrayAndTheAudienceMayBeAList(): void
    {
        $keys = JwtKeys::generate('ES256');

        $principal = self::authenticator($keys)->authenticate(self::bearer($keys->token(self::claims(['scope' => null, 'scp' => ['a', 'b'], 'aud' => ['other-api', self::AUDIENCE]]))));

        self::assertNotNull($principal);
        self::assertSame(['a', 'b'], $principal->scopes);
    }

    /**
     * @param \Closure(JwtKeys): string $forge builds the token that must be refused
     */
    #[DataProvider('refusedTokens')]
    public function testEveryInvalidTokenIsRefused(\Closure $forge): void
    {
        $keys = JwtKeys::generate('ES256');

        self::assertNull(self::authenticator($keys)->authenticate(self::bearer($forge($keys))));
    }

    /**
     * @return iterable<string, array{\Closure(JwtKeys): string}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'expired' => [static fn(JwtKeys $k): string => $k->token(self::claims(['exp' => time() - 3600]))];
        yield 'not valid yet' => [static fn(JwtKeys $k): string => $k->token(self::claims(['nbf' => time() + 3600]))];
        yield 'wrong issuer' => [static fn(JwtKeys $k): string => $k->token(self::claims(['iss' => 'https://evil.example.test']))];
        yield 'wrong audience' => [static fn(JwtKeys $k): string => $k->token(self::claims(['aud' => 'billing-api']))];
        yield 'no subject' => [static fn(JwtKeys $k): string => $k->token(self::claims(['sub' => null]))];
        yield 'no expiry' => [static fn(JwtKeys $k): string => $k->token(self::claims(['exp' => null]))];
        yield 'unknown key id' => [static fn(JwtKeys $k): string => $k->token(self::claims(), 'some-other-key')];
        yield 'no key id' => [static fn(JwtKeys $k): string => $k->token(self::claims(), withKeyId: false)];
        yield 'payload swapped after signing' => [static function (JwtKeys $k): string {
            [$head, , $signature] = explode('.', $k->token(self::claims()));

            return $head . '.' . JWT::urlsafeB64Encode((string) json_encode(self::claims(['sub' => 'mallory']))) . '.' . $signature;
        }];
        yield 'alg none without a signature' => [static fn(JwtKeys $k): string => JWT::urlsafeB64Encode((string) json_encode(['alg' => 'none', 'kid' => $k->keyId])) . '.' . JWT::urlsafeB64Encode((string) json_encode(self::claims())) . '.'];
        yield 'not even a token' => [static fn(JwtKeys $k): string => 'abc'];
    }

    public function testASymmetricKeyUsedAsAnHmacSecretIsRefused(): void
    {
        // the classic algorithm confusion: sign with HS256 using the (public) RSA key as the shared secret
        $keys = JwtKeys::generate('RS256');
        $forged = JWT::encode(self::claims(), $keys->publicPem, 'HS256', $keys->keyId);

        self::assertNull(self::authenticator($keys)->authenticate(self::bearer($forged)));
    }

    public function testAnAlgorithmThatIsNotOnTheAllowListIsRefusedEvenWithAValidSignature(): void
    {
        $keys = JwtKeys::generate('ES256');

        $onlyRsa = new JwtConfiguration(self::ISSUER, self::AUDIENCE, ['RS256']);

        self::assertNull(self::authenticator($keys, $onlyRsa)->authenticate(self::bearer($keys->token(self::claims()))));
    }

    public function testMissingOrMalformedAuthorizationHeadersGiveNoPrincipal(): void
    {
        $authenticator = self::authenticator(JwtKeys::generate('ES256'));

        self::assertNull($authenticator->authenticate(Request::create('/')));
        self::assertNull($authenticator->authenticate(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz'])));
        self::assertNull($authenticator->authenticate(self::bearer(str_repeat('a.', 5000))));
    }

    public function testClockDifferenceIsToleratedOnlyWithinTheConfiguredLeeway(): void
    {
        $keys = JwtKeys::generate('ES256');
        $justExpired = self::bearer($keys->token(self::claims(['exp' => time() - 10])));

        self::assertNotNull(self::authenticator($keys, new JwtConfiguration(self::ISSUER, self::AUDIENCE, leewaySeconds: 30))->authenticate($justExpired));
        self::assertNull(self::authenticator($keys, new JwtConfiguration(self::ISSUER, self::AUDIENCE, leewaySeconds: 0))->authenticate($justExpired));
    }

    /**
     * @param \Closure(): JwtConfiguration $build
     */
    #[DataProvider('unsafeConfigurations')]
    public function testUnsafeConfigurationFailsAtBoot(\Closure $build): void
    {
        $this->expectException(ConfigurationException::class);

        $build();
    }

    /**
     * @return iterable<string, array{\Closure(): JwtConfiguration}>
     */
    public static function unsafeConfigurations(): iterable
    {
        yield 'a shared-secret algorithm' => [static fn(): JwtConfiguration => new JwtConfiguration(self::ISSUER, self::AUDIENCE, ['HS256'])];
        yield 'the none algorithm' => [static fn(): JwtConfiguration => new JwtConfiguration(self::ISSUER, self::AUDIENCE, ['none'])];
        yield 'no algorithm at all' => [static fn(): JwtConfiguration => new JwtConfiguration(self::ISSUER, self::AUDIENCE, [])];
        yield 'no audience' => [static fn(): JwtConfiguration => new JwtConfiguration(self::ISSUER, '')];
        yield 'an absurd leeway' => [static fn(): JwtConfiguration => new JwtConfiguration(self::ISSUER, self::AUDIENCE, leewaySeconds: 3600)];
    }

    public function testItProtectsRoutesInTheKernelEndToEnd(): void
    {
        $keys = JwtKeys::generate('ES256');
        $kernel = new Kernel(
            TestKernel::FIXTURES . '/Auth/services.php',
            TestKernel::FIXTURES . '/Auth/routes.php',
            synthetic: [Authenticator::class => self::authenticator($keys)],
        );
        $get = static fn(string $path, ?string $token): int => $kernel->handle(null === $token ? Request::create($path) : self::bearer($token)->duplicate(server: ['REQUEST_URI' => $path] + (self::bearer($token)->server->all())))->getStatusCode();

        $withScope = $keys->token(self::claims(['scope' => 'orders:read']));
        $withoutScope = $keys->token(self::claims(['scope' => '']));

        self::assertSame(401, $get('/me', null));
        self::assertSame(200, $get('/me', $withoutScope));
        self::assertSame(403, $get('/orders', $withoutScope));
        self::assertSame(200, $get('/orders', $withScope));
    }
}
