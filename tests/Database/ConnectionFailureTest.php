<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\ConnectionFactory;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Tests\Support\Attempts;
use Slimfony\Core\Tests\Support\TestDb;

final class ConnectionFailureTest extends TestCase
{
    public function testWhenTheRollbackItselfFailsTheConnectionIsClosedAndTheErrorStopsTheWorker(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        $connection->method('rollBack')->willThrowException(new \RuntimeException('rollback failed'));
        $connection->expects(self::once())->method('close');

        $this->expectException(\RuntimeException::class);

        (new ConnectionLifecycle($connection))->reset();
    }

    public function testNothingIsRetriedInsideAnAlreadyOpenTransaction(): void
    {
        $connection = TestDb::connection();
        $attempts = new Attempts();
        $retry = new Retry($connection, sleep: static function (int $micros): void {});
        $driverError = new class ('x') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };

        $connection->beginTransaction();

        $this->expectException(ConnectionLost::class); // the outermost transaction owner decides, not the helper
        try {
            $retry->read(static function () use ($attempts, $driverError): never {
                ++$attempts->n;
                throw new ConnectionLost($driverError, null);
            });
        } finally {
            self::assertSame(1, $attempts->n);
        }
    }

    public function testTransactionPoolingModeStillRunsBoundQueries(): void
    {
        if (!TestDb::isPostgres()) {
            self::markTestSkipped('Needs a real server connection (TEST_DATABASE_URL).');
        }

        $connection = ConnectionFactory::create((string) getenv('TEST_DATABASE_URL'), transactionPooling: true);

        self::assertSame(42, TestDb::int($connection, 'SELECT ?::int', [42]));
    }
}
