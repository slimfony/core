<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Slimfony\Core\Database\ConnectionFactory;

/**
 * Real PostgreSQL when TEST_DATABASE_URL is set (CI always sets it), otherwise in-memory SQLite
 * for a quick local run. Tests that need server behaviour check isPostgres().
 */
final class TestDb
{
    public static function isPostgres(): bool
    {
        $url = getenv('TEST_DATABASE_URL');

        return \is_string($url) && '' !== $url;
    }

    public static function connection(): Connection
    {
        $url = getenv('TEST_DATABASE_URL');
        $connection = \is_string($url) && '' !== $url
            ? ConnectionFactory::create($url, applicationName: 'slimfony-tests')
            : DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $connection->executeStatement('DROP TABLE IF EXISTS slimfony_t');
        $connection->executeStatement('CREATE TABLE slimfony_t (v INTEGER NOT NULL)');

        return $connection;
    }

    public static function count(Connection $connection): int
    {
        return self::int($connection, 'SELECT COUNT(*) FROM slimfony_t');
    }

    /**
     * @param literal-string $sql
     * @param list<int|string> $params
     */
    public static function int(Connection $connection, string $sql, array $params = []): int
    {
        $value = $connection->fetchOne($sql, $params);

        return \is_int($value) || \is_string($value) ? (int) $value : throw new \UnexpectedValueException('Expected an integer scalar.');
    }
}
