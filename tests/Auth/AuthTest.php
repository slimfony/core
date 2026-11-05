<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Principal;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Support\FakeAuthenticator;
use Slimfony\Core\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;

final class AuthTest extends TestCase
{
    private function kernel(FakeAuthenticator $authenticator): Kernel
    {
        return new Kernel(
            TestKernel::FIXTURES . '/Auth/services.php',
            TestKernel::FIXTURES . '/Auth/routes.php',
            synthetic: [Authenticator::class => $authenticator],
        );
    }

    private static function authenticator(): FakeAuthenticator
    {
        return new FakeAuthenticator([
            'alice-token' => new Principal('alice'),
            'bob-token' => new Principal('bob', ['orders:read']),
        ]);
    }

    private static function get(string $path, ?string $token = null): Request
    {
        return Request::create($path, server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    public function testEverythingIsDeniedByDefaultExceptRoutesMarkedPublic(): void
    {
        $kernel = $this->kernel(self::authenticator());

        self::assertSame(200, $kernel->handle(self::get('/open'))->getStatusCode());

        $anonymous = $kernel->handle(self::get('/me'));
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame('Bearer', $anonymous->headers->get('WWW-Authenticate'));
        self::assertStringContainsString((string) $anonymous->headers->get('X-Request-Id'), (string) $anonymous->getContent(), 'the 401 carries the request id');

        self::assertSame(401, $kernel->handle(self::get('/me', 'forged-token'))->getStatusCode());
    }

    public function testUnknownPathsAreAnswered401NotTheRevealing404(): void
    {
        $kernel = $this->kernel(self::authenticator());

        self::assertSame(401, $kernel->handle(self::get('/no-such-endpoint'))->getStatusCode());
    }

    public function testAuthenticatedCallerReachesTheControllerAsAPrincipal(): void
    {
        $response = $this->kernel(self::authenticator())->handle(self::get('/me', 'alice-token'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('alice', $response->getContent());
    }

    public function testRouteScopesAreEnforced(): void
    {
        $kernel = $this->kernel(self::authenticator());

        self::assertSame(403, $kernel->handle(self::get('/orders', 'alice-token'))->getStatusCode(), 'authenticated but missing the scope');
        self::assertSame(200, $kernel->handle(self::get('/orders', 'bob-token'))->getStatusCode());
    }

    public function testPublicRoutesDoNotEvenConsultTheAuthenticator(): void
    {
        $authenticator = self::authenticator();

        $this->kernel($authenticator)->handle(self::get('/open', 'alice-token'));

        self::assertSame(0, $authenticator->calls);
    }

    public function testPrincipalNeedsASubjectAndIsAbsentWithoutTheMiddleware(): void
    {
        try {
            new Principal('');
            self::fail('An anonymous principal must not exist.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(ConfigurationException::class);
        Principal::from(self::get('/me'));
    }

    public function testRoutingFailuresStillPassThroughTheMiddlewareChain(): void
    {
        $kernel = $this->kernel(self::authenticator());

        // an authenticated DELETE on a GET-only route: routing fails (405), but the request id middleware has still run
        $response = $kernel->handle(Request::create('/open', 'DELETE', server: ['HTTP_AUTHORIZATION' => 'Bearer alice-token']));

        self::assertSame(405, $response->getStatusCode());
        self::assertNotNull($response->headers->get('X-Request-Id'));
    }
}
