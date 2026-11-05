<?php

declare(strict_types=1);

namespace Slimfony\Core\Database;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps one DBAL connection safe across requests in a long-lived worker.
 *
 * - reset() (end of request): a transaction left open is rolled back and logged. If even the rollback
 *   fails, the connection is closed and the error propagates, which stops the worker.
 * - beginRequest() (start of request): a connection idle for longer than $idleSeconds is closed, so the next
 *   query reconnects lazily instead of hitting a connection the server/pooler already dropped. No ping.
 *
 * @api
 */
final class ConnectionLifecycle implements ResetInterface
{
    private ?float $lastRequestEnd = null;
    /** @var \Closure(): float */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): float)|null $now seconds, monotonic-ish; defaults to microtime(true)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
        private int $idleSeconds = 30 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('idleSeconds must not be negative.'); },
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): float => microtime(true);
    }

    public function beginRequest(): void
    {
        $end = $this->lastRequestEnd;
        if (null !== $end && ($this->now)() - $end > $this->idleSeconds) {
            $this->connection->close();
        }
    }

    #[\Override]
    public function reset(): void
    {
        try {
            if ($this->connection->getTransactionNestingLevel() > 0) {
                $this->logger?->error('Transaction left open at end of request; rolling back', [
                    'nesting_level' => $this->connection->getTransactionNestingLevel(),
                ]);
                while ($this->connection->getTransactionNestingLevel() > 0) {
                    $this->connection->rollBack();
                }
            }
        } catch (\Throwable $e) {
            $this->connection->close();

            throw $e;
        } finally {
            $this->lastRequestEnd = ($this->now)();
        }
    }
}
