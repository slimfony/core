<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Http\BodySizeLimitMiddleware;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Tests\Support\SeenBody;
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
        self::assertSame(
            '4bf92f3577b34da6a3ce929d0e0e4736',
            self::traceIdFor('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'),
        );
        self::assertMatchesRegularExpression(
            '/^(?!0{32})[0-9a-f]{32}$/D',
            self::traceIdFor('00-00000000000000000000000000000000-00f067aa0ba902b7-01'),
        );
    }

    public function testTheTraceContinuesWithANewSpanForThisServiceAndAnOutgoingTraceparent(): void
    {
        $attributes = [];
        $context = new \Slimfony\Core\Log\RequestContext();
        new RequestIdMiddleware($context)->process(
            Request::create('/', server: ['HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00']),
            static function (Request $r) use (&$attributes, $context): Response {
                $attributes = $r->attributes->all() + ['logged_span' => $context->spanId];

                return new Response();
            },
        );

        self::assertIsString($attributes['span_id']);
        $span = $attributes['span_id'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $span);
        self::assertNotSame('00f067aa0ba902b7', $span, 'a new span of this service, the span of the caller is its parent');
        self::assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-' . $span . '-00', $attributes['traceparent'], 'the same trace, the flags of the caller');
        self::assertSame($span, $attributes['logged_span'], 'the logger tags the lines with the same span');
    }

    public function testARequestWithoutATraceparentStartsATraceThatIsSampled(): void
    {
        $traceparent = '';
        new RequestIdMiddleware()->process(Request::create('/'), static function (Request $r) use (&$traceparent): Response {
            $value = $r->attributes->get('traceparent');
            $traceparent = \is_string($value) ? $value : '';

            return new Response();
        });

        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/D', $traceparent);
    }

    private static function traceIdFor(string $traceparent): string
    {
        $trace = '';
        new RequestIdMiddleware()->process(
            Request::create('/', server: ['HTTP_TRACEPARENT' => $traceparent]),
            static function (Request $r) use (&$trace): Response {
                $id = $r->attributes->get('trace_id');
                $trace = \is_string($id) ? $id : '';

                return new Response();
            },
        );

        return $trace;
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

    public function testABodyWithoutADeclaredLengthIsLimitedByReadingNoMoreThanTheLimit(): void
    {
        $middleware = new BodySizeLimitMiddleware(100);
        $seen = new SeenBody();
        $next = static function (Request $request) use ($seen): Response {
            $seen->body = $request->getContent();

            return new Response();
        };
        $chunked = static function (string $body): Request { // as the web server hands over a chunked body: no Content-Length
            $request = Request::create('/', 'POST', content: $body);
            $request->headers->remove('Content-Length');

            return $request;
        };

        self::assertSame(413, $middleware->process($chunked(str_repeat('x', 101)), $next)->getStatusCode());
        self::assertNull($seen->body, 'the application never saw the oversized body');

        self::assertSame(200, $middleware->process($chunked(str_repeat('x', 100)), $next)->getStatusCode());
        self::assertSame(str_repeat('x', 100), $seen->body, 'a body within the limit is still whole for the application');
        self::assertSame(200, $middleware->process(Request::create('/', 'GET'), $next)->getStatusCode(), 'a request without a body is not read');
    }

    public function testSecurityHeadersAreSetAndExplicitPublicCachingIsRespected(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $plain = $middleware->process(Request::create('/'), static fn(): Response => new Response());
        self::assertSame('nosniff', $plain->headers->get('X-Content-Type-Options'));
        self::assertTrue($plain->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString("default-src 'none'", (string) $plain->headers->get('Content-Security-Policy'));

        $cached = $middleware->process(Request::create('/'), static fn(): Response => new Response()->setPublic()->setMaxAge(60));
        self::assertTrue($cached->headers->hasCacheControlDirective('max-age'));
        self::assertFalse($cached->headers->hasCacheControlDirective('no-store'));
    }

}
