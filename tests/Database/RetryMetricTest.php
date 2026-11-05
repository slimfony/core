<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\Attempts;
use Slimfony\Core\Tests\Support\FakeDriverException;
use Slimfony\Core\Tests\Support\InMemoryStorage;
use Slimfony\Core\Tests\Support\TestDb;

/** db_retries_total counts the retries that were actually made, by reason. */
final class RetryMetricTest extends TestCase
{
    private function retry(MetricsRegistry $registry): Retry
    {
        return new Retry(
            TestDb::connection(),
            sleep: static function (int $micros): void {},
            retries: $registry->counter('db_retries_total', 'Database retries.', ['reason']),
        );
    }

    public function testEveryRetryIsCountedByReason(): void
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $retry = $this->retry($registry);
        $deadlocks = new Attempts();
        $lost = new Attempts();

        $retry->transactional(static function () use ($deadlocks): void {
            if (++$deadlocks->n <= 2) {
                throw new DeadlockException(new FakeDriverException('deadlock'), null);
            }
        });
        $retry->transactional(static function () use ($lost): void {
            if (1 === ++$lost->n) {
                throw new ConnectionLost(new FakeDriverException('gone'), null);
            }
        }, idempotent: true);

        $out = $registry->render();
        self::assertStringContainsString('db_retries_total{reason="retryable"} 2', $out);
        self::assertStringContainsString('db_retries_total{reason="connection_lost"} 1', $out);
    }

    public function testTheFinalFailureIsNotCountedAsARetry(): void
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $this->expectException(DeadlockException::class);

        try {
            $this->retry($registry)->transactional(static function (Connection $db): never {
                throw new DeadlockException(new FakeDriverException('deadlock'), null);
            });
        } finally {
            self::assertStringContainsString('db_retries_total{reason="retryable"} 2', $registry->render(), '3 attempts are 2 retries');
        }
    }
}
