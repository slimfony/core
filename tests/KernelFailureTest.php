<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TestKernel;
use Symfony\Component\HttpFoundation\Request;

/** What must happen when the application or its configuration is broken. */
final class KernelFailureTest extends TestCase
{
    private const string FIXTURES = TestKernel::FIXTURES;

    public function testControllerThatDoesNotReturnAResponseIsA500WithoutLeak(): void
    {
        $logger = new RecordingLogger();

        $response = TestKernel::create(logger: $logger)->handle(Request::create('/notresponse'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('Response', (string) $response->getContent());
        self::assertSame(['error: Unhandled exception'], $logger->records);
    }

    public function testMiddlewareThatThrowsStillYieldsAProblemResponseNotAFatalError(): void
    {
        $kernel = new Kernel(self::FIXTURES . '/Bad/throwing_middleware.php', self::FIXTURES . '/Bad/empty_routes.php');

        $response = $kernel->handle(Request::create('/anything'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertStringNotContainsString('secret', (string) $response->getContent());
    }

    #[DataProvider('brokenConfigurations')]
    public function testMisconfigurationFailsAtBootNotOnTheFirstRequest(string $services, string $routes): void
    {
        $this->expectException(ConfigurationException::class);

        new Kernel(self::FIXTURES . $services, self::FIXTURES . $routes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenConfigurations(): iterable
    {
        yield 'route without a _controller' => ['/services.php', '/Bad/route_without_controller.php'];
        yield 'middleware id that is not a middleware' => ['/Bad/middleware_not_a_middleware.php', '/Bad/empty_routes.php'];
    }
}
