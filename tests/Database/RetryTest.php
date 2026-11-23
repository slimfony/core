<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Tests\Support\Attempts;
use Slimfony\Core\Tests\Support\FakeDriverException;
use Slimfony\Core\Tests\Support\TestDb;

final class RetryTest extends TestCase
{
    private static function driverError(): DriverException
    {
        return new class ('driver error') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
    }

    private static function retry(Connection $connection): Retry
    {
        return new Retry($connection, sleep: static function (int $micros): void {});
    }

    public function testDeadlockReRunsTheWholeTransactionAndNothingFromTheFailedAttemptSurvives(): void
    {
        $connection = TestDb::connection();
        $attempts = 0;

        $retry = self::retry($connection);
        $retry->transactional(static function (Connection $c) use (&$attempts): void {
            $c->executeStatement('INSERT INTO slimfony_t (v) VALUES (1)');
            if (1 === ++$attempts) {
                throw new DeadlockException(self::driverError(), null);
            }
        });

        self::assertSame(2, $attempts);
        self::assertSame(1, TestDb::count($connection));
    }

    public function testLostConnectionInATransactionIsNotRetriedUnlessIdempotent(): void
    {
        $connection = TestDb::connection();
        $attempts = new Attempts();
        $work = static function () use ($attempts): void {
            ++$attempts->n;
            if ($attempts->n < 2) {
                throw new ConnectionLost(self::driverError(), null);
            }
        };

        try {
            self::retry($connection)->transactional($work);
            self::fail('A non-idempotent transaction must not be retried after a lost connection.');
        } catch (ConnectionLost) {
            self::assertSame(1, $attempts->n);
        }

        $attempts->n = 0;
        self::retry(TestDb::connection())->transactional($work, idempotent: true);
        self::assertSame(2, $attempts->n);
    }

    public function testRetriesAreBounded(): void
    {
        $attempts = 0;

        $this->expectException(DeadlockException::class);
        try {
            self::retry(TestDb::connection())->transactional(static function () use (&$attempts): never {
                ++$attempts;
                throw new DeadlockException(self::driverError(), null);
            });
        } finally {
            self::assertSame(3, $attempts);
        }
    }

    public function testReallyKilledServerConnectionIsRecoveredByReadAndTransactional(): void
    {
        if (!TestDb::isPostgres()) {
            self::markTestSkipped('Needs a real server connection (TEST_DATABASE_URL).');
        }

        $connection = TestDb::connection();
        $admin = TestDb::connection();
        $retry = self::retry($connection);
        $pid = static fn(Connection $c): int => TestDb::int($c, 'SELECT pg_backend_pid()');

        $before = $pid($connection);
        $admin->executeQuery('SELECT pg_terminate_backend(' . $before . ')')->free();

        $after = $retry->read(static fn(Connection $c): int => $pid($c));
        self::assertNotSame($before, $after);

        $admin->executeQuery('SELECT pg_terminate_backend(' . $after . ')')->free();
        $retry->transactional(static function (Connection $c): void {
            $c->executeStatement('INSERT INTO slimfony_t (v) VALUES (1)');
        }, idempotent: true);
        self::assertSame(1, TestDb::count($connection));
    }
    public function testAServerThatRefusesWritesIsRetriedOnANewConnectionEvenForANonIdempotentTransaction(): void
    {
        $attempts = new Attempts();
        $refusal = new \Doctrine\DBAL\Exception\DriverException(new FakeDriverException('cannot execute INSERT in a read-only transaction', '25006'), null);

        self::retry(TestDb::connection())->transactional(static function () use ($attempts, $refusal): void {
            if (1 === ++$attempts->n) {
                throw $refusal; // the old primary after a failover: nothing ran
            }
        }, idempotent: false);

        self::assertSame(2, $attempts->n, 'refused once, then done on a new connection');
    }

    public function testOtherDriverErrorsAreNotRetried(): void
    {
        $attempts = new Attempts();
        $error = new \Doctrine\DBAL\Exception\DriverException(self::driverError(), null); // no failover SQLSTATE

        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        try {
            self::retry(TestDb::connection())->transactional(static function () use ($attempts, $error): never {
                ++$attempts->n;

                throw $error;
            });
        } finally {
            self::assertSame(1, $attempts->n);
        }
    }

    public function testARealReadOnlyServerIsLeftBehindByReconnecting(): void
    {
        if (!TestDb::isPostgres()) {
            self::markTestSkipped('Needs a real server connection (TEST_DATABASE_URL).');
        }

        $connection = TestDb::connection();
        $attempts = new Attempts();

        self::retry($connection)->transactional(static function (Connection $c) use ($attempts): void {
            if (1 === ++$attempts->n) {
                $c->executeStatement('SET TRANSACTION READ ONLY'); // this transaction behaves as if it ran on a standby: the write below is refused with SQLSTATE 25006
            }
            $c->executeStatement('INSERT INTO slimfony_t (v) VALUES (1)');
        });

        self::assertSame(2, $attempts->n, 'the write was refused (SQLSTATE 25006) and went through on the new connection');
        self::assertSame(1, TestDb::count($connection));
    }

    public function testALockTimeoutIsRetriedLikeADeadlock(): void
    {
        $attempts = new Attempts();
        $timeout = new \Doctrine\DBAL\Exception\DriverException(new FakeDriverException('canceling statement due to lock timeout', '55P03'), null);

        self::retry(TestDb::connection())->transactional(static function () use ($attempts, $timeout): void {
            if (1 === ++$attempts->n) {
                throw $timeout; // the statement waited for a lock and never ran
            }
        }, idempotent: false);

        self::assertSame(2, $attempts->n);
    }

    public function testAFailureToConnectIsRetriedEvenForANonIdempotentTransaction(): void
    {
        if (!\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('Needs pdo_pgsql to fail to connect the way PostgreSQL does.');
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'u', 'password' => 'p', 'dbname' => 'd']); // nobody listens
        $slept = new Attempts();
        $ran = new Attempts();
        $retry = new Retry($connection, sleep: static function (int $micros) use ($slept): void {
            ++$slept->n;
        });

        try {
            $retry->transactional(static function () use ($ran): void {
                ++$ran->n;
            }, idempotent: false);
            self::fail('there is no database');
        } catch (\Doctrine\DBAL\Exception\ConnectionException) {
        }

        self::assertSame(0, $ran->n, 'the work never ran: there was no connection');
        self::assertSame(2, $slept->n, 'three attempts, a pause between them');
    }
}
