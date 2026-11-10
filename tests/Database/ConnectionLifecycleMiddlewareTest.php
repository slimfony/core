<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Database;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\ConnectionLifecycleMiddleware;
use Slimfony\Core\Tests\Support\TestDb;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConnectionLifecycleMiddlewareTest extends TestCase
{
    public function testItChecksTheIdleConnectionBeforeTheRequestAndPassesTheRequestOn(): void
    {
        $clockReads = 0;
        $lifecycle = new ConnectionLifecycle(TestDb::connection(), now: static function () use (&$clockReads): float {
            ++$clockReads;

            return 1000.0;
        });
        $lifecycle->reset(); // the end of a request: from now on the idle time is measured
        $readsBefore = $clockReads;

        $response = new ConnectionLifecycleMiddleware($lifecycle)->process(Request::create('/'), static fn(Request $request): Response => new Response('answer'));

        self::assertGreaterThan($readsBefore, $clockReads, 'the idle check ran before the request');
        self::assertSame('answer', $response->getContent());
    }
}
