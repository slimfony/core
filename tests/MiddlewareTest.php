<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Http\BodySizeLimitMiddleware;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MiddlewareTest extends TestCase
{
    public function testForgedRequestIdIsReplacedButValidOneIsKept(): void
    {
        $middleware = new RequestIdMiddleware();
        $seen = null;
        $next = static function (Request $r) use (&$seen): Response {
            $seen = $r->attributes->get('request_id');

            return new Response();
        };

        $middleware->process(Request::create('/', server: ['HTTP_X_REQUEST_ID' => "evil\r\nSet-Cookie: x=1"]), $next);
        self::assertIsString($seen);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $seen);

        $response = $middleware->process(Request::create('/', server: ['HTTP_X_REQUEST_ID' => 'abc-12345']), $next);
        self::assertSame('abc-12345', $seen);
        self::assertSame('abc-12345', $response->headers->get('X-Request-Id'));
    }

    public function testTraceIdComesOnlyFromAValidNonZeroTraceparent(): void
    {
        $middleware = new RequestIdMiddleware();
        $trace = null;
        $next = static function (Request $r) use (&$trace): Response {
            $trace = $r->attributes->get('trace_id');

            return new Response();
        };

        $middleware->process(Request::create('/', server: ['HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']), $next);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace);

        $middleware->process(Request::create('/', server: ['HTTP_TRACEPARENT' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01']), $next);
        self::assertNotSame('00000000000000000000000000000000', $trace);
    }

    public function testOversizedBodyIsRejectedBeforeTheApplicationRuns(): void
    {
        $called = false;
        $next = static function () use (&$called): Response {
            $called = true;

            return new Response();
        };
        $middleware = new BodySizeLimitMiddleware(100);

        $response = $middleware->process(Request::create('/', 'POST', server: ['CONTENT_LENGTH' => '101']), $next);
        self::assertSame(413, $response->getStatusCode());
        self::assertFalse($called);

        self::assertSame(413, $middleware->process(Request::create('/', 'POST', server: ['CONTENT_LENGTH' => '-1']), $next)->getStatusCode());
        self::assertSame(200, $middleware->process(Request::create('/', 'POST', server: ['CONTENT_LENGTH' => '100']), $next)->getStatusCode());
    }

    public function testSecurityHeadersAreSetAndExplicitPublicCachingIsRespected(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $plain = $middleware->process(Request::create('/'), static fn(): Response => new Response());
        self::assertSame('nosniff', $plain->headers->get('X-Content-Type-Options'));
        self::assertTrue($plain->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString("default-src 'none'", (string) $plain->headers->get('Content-Security-Policy'));

        $cached = $middleware->process(Request::create('/'), static fn(): Response => (new Response())->setPublic()->setMaxAge(60));
        self::assertTrue($cached->headers->hasCacheControlDirective('max-age'));
        self::assertFalse($cached->headers->hasCacheControlDirective('no-store'));
    }
}
