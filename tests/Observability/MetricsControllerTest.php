<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Exception\HttpException;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\InMemoryStorage;
use Symfony\Component\HttpFoundation\Request;

final class MetricsControllerTest extends TestCase
{
    private const string TOKEN = 'correct-horse-battery-staple';

    private static function registry(): MetricsRegistry
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $registry->counter('demo_total', 'Demo.')->inc();

        return $registry;
    }

    private static function request(?string $authorization = null, string $ip = '203.0.113.7'): Request
    {
        return Request::create('/metrics', server: ['REMOTE_ADDR' => $ip] + (null === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization]));
    }

    public function testItRefusesToExistUnprotectedOrWithAWeakToken(): void
    {
        foreach ([[null, []], ['short', []]] as [$token, $cidrs]) {
            try {
                new MetricsController(self::registry(), $token, $cidrs);
                self::fail('A /metrics endpoint without real protection must not be constructible.');
            } catch (ConfigurationException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testServesTheMetricsToTheRightBearerTokenOrAllowedIp(): void
    {
        $byToken = new MetricsController(self::registry(), self::TOKEN);
        $response = $byToken(self::request('Bearer ' . self::TOKEN));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('demo_total 1', (string) $response->getContent());

        $byIp = new MetricsController(self::registry(), allowedCidrs: ['10.0.0.0/8']);
        self::assertSame(200, $byIp(self::request(ip: '10.1.2.3'))->getStatusCode());
    }

    public function testWrongOrMissingCredentialsAreRejected(): void
    {
        $byToken = new MetricsController(self::registry(), self::TOKEN);
        foreach ([null, 'Bearer wrong-token-value-xx', self::TOKEN] as $authorization) {
            try {
                $byToken(self::request($authorization));
                self::fail('Must be rejected.');
            } catch (HttpException $e) {
                self::assertSame(401, $e->status);
                self::assertSame(['WWW-Authenticate' => 'Bearer'], $e->headers);
            }
        }

        $byIp = new MetricsController(self::registry(), allowedCidrs: ['10.0.0.0/8']);
        $this->expectException(HttpException::class);
        $byIp(self::request(ip: '192.0.2.9'));
    }
}
