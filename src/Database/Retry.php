<?php

declare(strict_types=1);

namespace Slimfony\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Observability\Counter;

/**
 * Bounded retries for the two failures that are safe to retry:
 *
 * - Deadlock / lock timeout / serialization failure (RetryableException): the server aborted the transaction,
 *   so the WHOLE transaction callable is run again.
 * - Lost connection (ConnectionLost, ConnectionException): reads always; transactions only with $idempotent = true,
 *   because a connection lost during COMMIT may or may not have committed.
 * - A server that does not accept the work at all (SQLSTATE 25006, a read-only server: the old primary after a failover, or
 *   a standby; 57P03, a server that is starting up or recovering): the statement was refused before anything ran, so the
 *   work is retried on a new connection whether it is idempotent or not.
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
    /** value of the counter's label for a server that refused the work (read-only, starting up): see the class comment */
    public const string REASON_FAILOVER = 'failover';

    /** @var list<string> */
    private const array FAILOVER_SQLSTATES = ['25006', '57P03'];

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep   microseconds; defaults to usleep
     * @param Counter|null                $retries counts every retry that is actually made; it must declare exactly one
     *                                             label, whose value is REASON_CONNECTION_LOST, REASON_RETRYABLE or REASON_FAILOVER
     * @param Counter|null                $exhausted counts the operations that failed even after all attempts (same single label):
     *                                             the failures a retry could not save, the ones worth an alert
     * @param int                         $backoffMicros the pause before attempt n+1 is a random time between 1 ms and n times this
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
        private int $maxAttempts = 3 { set => $value >= 1 ? $value : throw new \InvalidArgumentException('maxAttempts must be at least 1.'); },
        ?\Closure $sleep = null,
        private readonly ?Counter $retries = null,
        private readonly ?Counter $exhausted = null,
        private int $backoffMicros = 20_000 { set => $value >= 1_000 ? $value : throw new \InvalidArgumentException('backoffMicros must be at least 1000.'); },
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
            } catch (ConnectionException $e) { // includes ConnectionLost
                $this->connection->close(); // forces a clean reconnect and resets DBAL's transaction state
                if (!$retryConnectionLoss) {
                    throw $e;
                }
                $this->again($attempt, self::REASON_CONNECTION_LOST, $e, 'Database connection lost, retrying');
            } catch (RetryableException $e) {
                $this->again($attempt, self::REASON_RETRYABLE, $e, 'Retryable database error, retrying transaction');
            } catch (DriverException $e) {
                if (!\in_array($e->getSQLState(), self::FAILOVER_SQLSTATES, true)) {
                    throw $e;
                }
                $this->connection->close(); // the next attempt connects again, to whichever server is the primary by then
                $this->again($attempt, self::REASON_FAILOVER, $e, 'The database server is not accepting writes (failover?), retrying on a new connection');
            }

            ($this->sleep)(random_int(1_000, $this->backoffMicros * $attempt)); // jitter
        }
    }

    /** Counts and logs a retry that is going to happen; when the attempts are used up, counts that and lets the error go. */
    private function again(int $attempt, string $reason, \Throwable $e, string $message): void
    {
        if ($attempt >= $this->maxAttempts) {
            $this->exhausted?->inc([$reason]);

            throw $e;
        }
        $this->retries?->inc([$reason]);
        $this->logger?->warning($message, ['attempt' => $attempt, 'error' => $e::class]);
    }
}
