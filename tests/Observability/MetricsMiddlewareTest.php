<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Observability\MetricsMiddleware;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\InMemoryStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MetricsMiddlewareTest extends TestCase
{
    public function testLabelsUseTheRouteNameNotTheRawUrlSoCardinalityStaysBounded(): void
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $middleware = new MetricsMiddleware($registry);

        $matched = Request::create('/hello/12345');
        $matched->attributes->set(Attributes::ROUTE, 'hello');
        $middleware->process($matched, static fn(): Response => new Response('', 200));

        $noRoute = Request::create('/random-scanner-path-98765', 'PROPFIND');
        $middleware->process($noRoute, static fn(): Response => new Response('', 404));

        $out = $registry->render();
        self::assertStringContainsString('http_requests_total{method="GET",route="hello",status="200"} 1', $out);
        self::assertStringContainsString('http_requests_total{method="OTHER",route="unmatched",status="404"} 1', $out);
        self::assertStringContainsString('http_request_duration_seconds_count{method="GET",route="hello"} 1', $out);
        self::assertStringNotContainsString('12345', $out);
        self::assertStringNotContainsString('98765', $out);
    }
}
