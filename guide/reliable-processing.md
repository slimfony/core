# Reliable processing

[Back to the README](../README.md)

A consumer can die at any line. These rules, in this order, make that harmless; the [demo](https://github.com/slimfony/demo) runs all of them under a chaos script that kills importers and cuts database connections.

1. **Write the message with the data (outbox).** Insert the event into an `outbox` table in the same transaction as the change. A relay publishes the rows (`SELECT ... FOR UPDATE SKIP LOCKED`, so relays never fight over a row) and marks them published. There is never a state with the data but no event.
2. **Claim work with a lease that expires.** A consumer marks a unit of work `processing` with a deadline (`claimed_until`) that is longer than the work should take. A unit whose deadline has passed is free again, so a killed consumer cannot hold work forever.
3. **Make the handler idempotent.** Delivery is at least once: the same message arrives twice after a crash or a timeout. Key the result on the message (a unique constraint, `INSERT ... ON CONFLICT DO NOTHING`, or a status check inside the claim), so the second run changes nothing.
4. **Sweep.** A small periodic job re-publishes outbox rows that stayed unpublished and releases claims whose deadline passed. It is the safety net for everything above; run it in the relay.
5. **Give up visibly.** After the broker's maximum number of deliveries, mark the unit `failed` with the reason instead of retrying forever, and alert on the count (see `exhausted` of `Retry`).

**`Idempotency-Key` for the API.** A client that did not see the answer sends the request again. With `IdempotencyMiddleware` (after `AuthMiddleware`, with a `DbalIdempotencyStore` on your database, its table is `DbalIdempotencyStore::TABLE_SQL`) a route marked `_idempotent: true` answers the repeated `POST` or `PATCH` with the first answer (header `Idempotent-Replayed: true`) instead of doing the work twice. The same key with a different request is `422`, the same request while the first still runs is `409` with `Retry-After`, a `5xx` or an exception is not kept (the client may try again), and keys belong to the caller (the token's subject). The store keeps the answer after the work is done, so if the process dies between the two, the request runs again once the lock has expired: for work that must never happen twice, give the handler a unique column and fill it with the request attribute `Attributes::IDEMPOTENCY_KEY`.

**Deadlocks.** Two transactions that lock the same rows in different order deadlock, and `Retry::transactional()` then re-runs the loser. That is correct but wasteful under load. Prevention is cheaper: lock rows in one agreed order (for example `ORDER BY id` in the statement that locks them, or sort the keys before a batch of upserts). In the demo's import benchmark this alone took 1000 files with 8 importers from 67 s to 13.9 s. If a deadlock does happen, PostgreSQL waits `deadlock_timeout` (1 s by default) before it checks for one; the waiting transaction is stuck for that long. For workloads with short transactions, `deadlock_timeout` of 200 ms to 500 ms is a reasonable trade, but keep `Retry`'s attempts small: it is a net, not a strategy.

