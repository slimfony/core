<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Database;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\InMemoryStorage;
use Slimfony\Core\Tests\Support\TestDb;

final class LeakedTransactionMetricTest extends TestCase
{
    public function testEveryLeakedTransactionIsCounted(): void
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $connection = TestDb::connection();
        $lifecycle = new ConnectionLifecycle($connection, leakedTransactions: $registry->counter('db_transactions_leaked_total', 'Transactions left open by a request.'));

        $lifecycle->reset(); // clean request: nothing counted
        $connection->beginTransaction();
        $lifecycle->reset();

        self::assertStringContainsString("db_transactions_leaked_total 1\n", $registry->render());
    }
}
