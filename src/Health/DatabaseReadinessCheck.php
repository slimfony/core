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
        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable $e) {
            $this->connection->close(); // a dead connection DBAL did not notice must not make every later probe fail too

            throw $e;
        }
    }
}
