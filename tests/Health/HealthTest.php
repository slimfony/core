<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Health;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Health\ReadinessController;
use Slimfony\Core\Tests\Support\FakeCheck;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Symfony\Component\HttpFoundation\Request;

final class HealthTest extends TestCase
{
    public function testLivenessAnswersWithoutTouchingAnyDependency(): void
    {
        $response = new LivenessController()(Request::create('/healthz'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());
    }

    public function testReadinessReportsFailedChecksByNameOnlyAndLogsTheRealError(): void
    {
        $check = new FakeCheck();
        $logger = new RecordingLogger();
        $time = 100.0;
        $readiness = new ReadinessController([$check], $logger, 2, static function () use (&$time): float {
            return $time;
        });

        $ok = $readiness(Request::create('/readyz'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('{"status":"ok"}', $ok->getContent());

        $check->healthy = false;
        $time += 1; // inside the cache window: probes must not hammer the dependency
        self::assertSame(200, $readiness(Request::create('/readyz'))->getStatusCode());
        self::assertSame(1, $check->calls);

        $time += 2; // window over: checked again
        $down = $readiness(Request::create('/readyz'));
        self::assertSame(503, $down->getStatusCode());
        self::assertSame('{"status":"unavailable","failed":["fake"]}', $down->getContent());
        self::assertStringNotContainsString('10.0.0.5', $down->getContent(), 'internal detail must stay in the log');
        self::assertSame(['warning: Readiness check failed: {check}'], $logger->records);
        self::assertSame(2, $check->calls);
    }
}
