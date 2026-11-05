<?php

declare(strict_types=1);

namespace Slimfony\Core\Health;

use Doctrine\DBAL\Connection;

/**
 * Ready when the database answers a trivial query. Bound the wait with the connect timeout of
 * ConnectionFactory and a server-side statement_timeout.
 *
 * @api
 */
final class DatabaseReadinessCheck implements ReadinessCheck
{
    public function __construct(private readonly Connection $connection) {}

    #[\Override]
    public function name(): string
    {
        return 'database';
    }

    #[\Override]
    public function check(): void
    {
        $this->connection->fetchOne('SELECT 1');
    }
}
