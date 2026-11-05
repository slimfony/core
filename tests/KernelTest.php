<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Slimfony\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class KernelTest extends TestCase
{
    private function kernel(bool $debug = false, ?\Psr\Log\LoggerInterface $logger = null): Kernel
    {
        return new Kernel(__DIR__ . '/Fixtures/services.php', __DIR__ . '/Fixtures/routes.php', $debug, $logger);
    }

    /**
     * @return array<mixed>
     */
    private static function problem(Response $response): array
    {
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    public function testRoutedControllerGetsAutowiredServiceAndRouteParameters(): void
    {
        $response = $this->kernel()->handle(Request::create('/hello/world'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello world', $response->getContent());
    }

    public function testUnknownPathIs404AndWrongMethodIs405WithAllowHeader(): void
    {
        $kernel = $this->kernel();

        self::assertSame(404, $kernel->handle(Request::create('/nope'))->getStatusCode());

        $response = $kernel->handle(Request::create('/hello/world', 'POST'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testUnexpectedExceptionNeverLeaksOutsideDebugButIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = $message . ':' . (($context['exception'] ?? null) instanceof \Throwable ? $context['exception']->getMessage() : '');
            }
        };

        $response = $this->kernel(false, $logger)->handle(Request::create('/boom'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret', (string) $response->getContent());
        self::assertSame(['Unhandled exception:secret db password'], $logger->errors);

        $debug = $this->kernel(true)->handle(Request::create('/boom'));
        self::assertStringContainsString('secret db password', (string) $debug->getContent());
    }

    public function testApplicationHttpExceptionBecomesProblemWithItsHeaders(): void
    {
        $response = $this->kernel()->handle(Request::create('/gone'));

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('test', $response->headers->get('X-Reason'));
        self::assertSame('moved on', self::problem($response)['detail']);
    }

    public function testMiddlewareSeesErrorResponsesToo(): void
    {
        $kernel = $this->kernel();

        self::assertSame('404', $kernel->handle(Request::create('/nope'))->headers->get('X-Seen-Status'));
        self::assertSame('500', $kernel->handle(Request::create('/boom'))->headers->get('X-Seen-Status'));
    }
}
