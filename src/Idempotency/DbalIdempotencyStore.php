<?php

declare(strict_types=1);

namespace Slimfony\Core\Idempotency;

use Doctrine\DBAL\Connection;

/**
 * Keeps the keys in one table of the service's database (the DDL is TABLE_SQL; it runs on PostgreSQL and SQLite).
 *
 * - A finished answer is kept for $ttlSeconds, then the key is free again.
 * - A claim that was never finished (the worker died) is taken over after $lockSeconds, so a key cannot block for ever.
 * - Every call is one statement of its own (no transaction of the caller is joined): an answer is kept only after the
 *   work is done. If the process dies between the two, the request runs again after the lock expires: for work that must
 *   never happen twice, the handler needs its own guard too (see Attributes::IDEMPOTENCY_KEY, a unique column).
 *
 * @api
 */
final class DbalIdempotencyStore implements IdempotencyStore
{
    public const string TABLE_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS idempotency_keys (
            idempotency_key TEXT PRIMARY KEY,
            fingerprint     TEXT NOT NULL,
            state           TEXT NOT NULL,
            response_status INTEGER,
            response_body   TEXT,
            content_type    TEXT,
            created_at      BIGINT NOT NULL
        )
        SQL;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): int)|null $now seconds; defaults to time()
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly int $ttlSeconds = 86_400,
        private readonly int $lockSeconds = 60,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    #[\Override]
    public function begin(string $key, string $fingerprint): Outcome
    {
        $now = ($this->now)();
        $this->connection->executeStatement(
            "DELETE FROM idempotency_keys WHERE idempotency_key = ? AND ((state = 'done' AND created_at < ?) OR (state = 'working' AND created_at < ?))",
            [$key, $now - $this->ttlSeconds, $now - $this->lockSeconds],
        );

        $claimed = $this->connection->executeStatement(
            "INSERT INTO idempotency_keys (idempotency_key, fingerprint, state, created_at) VALUES (?, ?, 'working', ?) ON CONFLICT (idempotency_key) DO NOTHING",
            [$key, $fingerprint, $now],
        );
        if (1 === $claimed) {
            return Outcome::Fresh;
        }

        $row = $this->connection->fetchAssociative('SELECT fingerprint, state FROM idempotency_keys WHERE idempotency_key = ?', [$key]);
        if (false === $row) {
            return Outcome::InProgress; // removed between our two statements: the client asks again in a moment
        }

        return match (true) {
            $row['fingerprint'] !== $fingerprint => Outcome::Mismatch,
            'done' === $row['state'] => Outcome::Done,
            default => Outcome::InProgress,
        };
    }

    #[\Override]
    public function stored(string $key): ?StoredResponse
    {
        $row = $this->connection->fetchAssociative("SELECT response_status, response_body, content_type FROM idempotency_keys WHERE idempotency_key = ? AND state = 'done'", [$key]);
        if (false === $row || !is_numeric($row['response_status'] ?? null) || !\is_string($row['response_body'] ?? null) || !\is_string($row['content_type'] ?? null)) {
            return null;
        }
        $body = base64_decode($row['response_body'], true);

        return false === $body ? null : new StoredResponse((int) $row['response_status'], $body, $row['content_type']);
    }

    #[\Override]
    public function complete(string $key, StoredResponse $response): void
    {
        $this->connection->executeStatement(
            "UPDATE idempotency_keys SET state = 'done', response_status = ?, response_body = ?, content_type = ?, created_at = ? WHERE idempotency_key = ?",
            [$response->status, base64_encode($response->body), $response->contentType, ($this->now)(), $key],
        );
    }

    #[\Override]
    public function release(string $key): void
    {
        $this->connection->executeStatement('DELETE FROM idempotency_keys WHERE idempotency_key = ?', [$key]);
    }
}
