<?php

declare(strict_types=1);

namespace Slimfony\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Observability\Counter;

/**
 * Bounded retries for the two failures that are safe to retry:
 *
 * - Deadlock / lock timeout / serialization failure (RetryableException): the server aborted the transaction,
 *   so the WHOLE transaction callable is run again.
 * - ConnectionLost: reads always; transactions only with $idempotent = true, because a connection lost during
 *   COMMIT may or may not have committed.
 *
 * Inside an already open transaction nothing is retried: the outermost owner decides.
 *
 * @api
 */
final class Retry
{
    /** value of the counter's label for a lost connection */
    public const string REASON_CONNECTION_LOST = 'connection_lost';
    /** value of the counter's label for a deadlock, lock timeout or serialization failure */
    public const string REASON_RETRYABLE = 'retryable';

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep   microseconds; defaults to usleep
     * @param Counter|null                $retries counts every retry that is actually made; it must declare exactly one
     *                                             label, whose value is REASON_CONNECTION_LOST or REASON_RETRYABLE
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
        private int $maxAttempts = 3 { set => $value >= 1 ? $value : throw new \InvalidArgumentException('maxAttempts must be at least 1.'); },
        ?\Closure $sleep = null,
        private readonly ?Counter $retries = null,
    ) {
        $this->sleep = $sleep ?? usleep(...);
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work read-only work (re-run on a lost connection)
     *
     * @return T
     */
    public function read(\Closure $work): mixed
    {
        return $this->attempt(fn(): mixed => $work($this->connection), true);
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    public function transactional(\Closure $work, bool $idempotent = false): mixed
    {
        return $this->attempt(fn(): mixed => $this->connection->transactional($work), $idempotent);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $once
     *
     * @return T
     */
    private function attempt(\Closure $once, bool $retryConnectionLoss): mixed
    {
        if ($this->connection->getTransactionNestingLevel() > 0) {
            return $once();
        }

        for ($attempt = 1;; ++$attempt) {
            try {
                return $once();
            } catch (ConnectionLost $e) {
                $this->connection->close(); // forces a clean reconnect and resets DBAL's transaction state
                if (!$retryConnectionLoss || $attempt >= $this->maxAttempts) {
                    throw $e;
                }
                $this->retries?->inc([self::REASON_CONNECTION_LOST]);
                $this->logger?->warning('Database connection lost, retrying', ['attempt' => $attempt]);
            } catch (RetryableException $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }
                $this->retries?->inc([self::REASON_RETRYABLE]);
                $this->logger?->warning('Retryable database error, retrying transaction', ['attempt' => $attempt, 'error' => $e::class]);
            }

            ($this->sleep)(random_int(1_000, 20_000 * $attempt)); // jitter
        }
    }
}
