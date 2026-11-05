<?php

declare(strict_types=1);

namespace Slimfony\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * PostgreSQL connection with worker-safe defaults: never persistent, bounded connect timeout,
 * application_name for pg_stat_activity. The DSN carries credentials: never log it.
 */
final class ConnectionFactory
{
    /**
     * @param bool $transactionPooling PgBouncer in transaction mode (before 1.21 without max_prepared_statements):
     *                                 emulated prepares, because server-side prepared statements do not survive it.
     *                                 Bound parameters are still used; do not combine with session state (SET, advisory locks, LISTEN).
     */
    public static function create(
        string $dsn,
        bool $transactionPooling = false,
        int $connectTimeoutSeconds = 3,
        string $applicationName = 'slimfony',
    ): Connection {
        $params = new DsnParser(['pdo-pgsql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql'])->parse($dsn);
        $params['application_name'] = $applicationName;
        $params['driverOptions'] = [\PDO::ATTR_TIMEOUT => $connectTimeoutSeconds, \PDO::ATTR_PERSISTENT => false]
            + ($transactionPooling ? [\PDO::ATTR_EMULATE_PREPARES => true] : []);

        return DriverManager::getConnection($params);
    }
}
