<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Health;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Health\DatabaseReadinessCheck;
use Slimfony\Core\Tests\Support\TestDb;

final class DatabaseReadinessCheckTest extends TestCase
{
    public function testItIsReadyWhenTheDatabaseAnswers(): void
    {
        $check = new DatabaseReadinessCheck(TestDb::connection());

        $check->check();

        self::assertSame('database', $check->name());
    }

    public function testItIsNotReadyWhenTheDatabaseCannotBeReached(): void
    {
        $check = new DatabaseReadinessCheck(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/no/such/directory/db.sqlite']));

        $this->expectException(Exception::class);

        $check->check();
    }
}
