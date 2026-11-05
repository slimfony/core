<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Database;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TestDb;

final class ConnectionLifecycleTest extends TestCase
{
    public function testTransactionLeftOpenByARequestIsRolledBackAndLogged(): void
    {
        $logger = new RecordingLogger();
        $connection = TestDb::connection();
        $lifecycle = new ConnectionLifecycle($connection, $logger);

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO slimfony_t (v) VALUES (1)');
        $connection->beginTransaction(); // nested (savepoint): both levels must be unwound
        $lifecycle->reset();

        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, TestDb::count($connection), 'the leaked transaction must not be committed');
        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('error: Transaction left open', $logger->records[0]);
    }

    public function testIdleConnectionIsRecycledButABusyOneIsKept(): void
    {
        if (!TestDb::isPostgres()) {
            self::markTestSkipped('Needs a real server connection (TEST_DATABASE_URL).');
        }

        $connection = TestDb::connection();
        $time = 1000.0;
        $lifecycle = new ConnectionLifecycle($connection, idleSeconds: 30, now: static function () use (&$time): float {
            return $time;
        });
        $pid = static fn(): int => TestDb::int($connection, 'SELECT pg_backend_pid()');

        $first = $pid();
        $lifecycle->reset();

        $time += 5; // short pause: keep
        $lifecycle->beginRequest();
        self::assertSame($first, $pid());

        $lifecycle->reset();
        $time += 31; // longer than the idle limit: recycle
        $lifecycle->beginRequest();
        self::assertNotSame($first, $pid());
    }
}
