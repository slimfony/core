# Failure behaviour and restarts

[Back to the README](../README.md)

What the core does when something goes wrong, and what is left to the platform. A restart loses what is in memory and what is in flight; the core keeps the *data* consistent, and the caller or the broker repeats what was lost (see [Reliable processing](reliable-processing.md)).

| What happens | What the core does | What you set |
|---|---|---|
| A request throws | A generic `500` problem+json without internals, logged with the request id; the worker goes on. | `APP_DEBUG` stays off outside development. |
| A request is slow or hangs | Nothing: the core has no deadline of its own. | Timeouts at the edge (Caddy `timeouts`: `read_header`, `read_body`, `write`) and in the database (`statement_timeout`, the connect timeout of `ConnectionFactory`). |
| Too much load | Nothing: the core does not queue or shed load. | The web server's queue and wait settings, rate limits and `429`/`503` with `Retry-After` at the ingress; `request_body max_size` at the edge. |
| A state reset fails | The worker stops (the state is unknown); the server starts it again. | |
| A worker restarts by itself (`MAX_REQUESTS`, memory limit) | It stops after the response is sent; the other threads keep serving. | `MAX_REQUESTS`, `maxMemoryBytes`. |
| A pod is stopped by a deploy | The server finishes the requests in flight; a consumer finishes its message on SIGTERM. | `maxUnavailable: 0`, a `readinessProbe` that fails first, a `preStop` sleep of 5 to 10 seconds (the load balancer needs a moment to stop sending), `terminationGracePeriodSeconds` above your longest request or message, a `PodDisruptionBudget`. See `k8s/` in the demo. |
| A process is killed (out of memory, crash, node lost) | The request in flight is lost; the database rolls back its open transaction; a consumer's message is not acknowledged and is delivered again. | Idempotent handlers and `Idempotency-Key` ([Reliable processing](reliable-processing.md)); the broker's acknowledgement wait. |
| Deadlock or serialization failure | `Retry` runs the whole transaction again, a few times, with jitter. | `maxAttempts`, `backoffMicros`; alert on the `exhausted` counter. |
| The database connection is lost | `Retry` repeats a read; a write only when you declare it `idempotent: true` (a lost COMMIT may or may not have happened). A connection that cannot be made because the server is away or starting is repeated for any work, because nothing ran; one that is refused for good (a wrong password, an unknown database or role, no `pg_hba.conf` entry) fails at once. PostgreSQL reports all of them as SQLSTATE 08006, so the text of the message tells them apart. | `idempotent: true` where it is true. |
| A database failover (the old primary is read-only, the new one is starting) | `Retry` repeats the work on a new connection, idempotent or not: the server refused it before anything ran. | Connect through a name that follows the primary (a pooler, a service). |
| The database is away for several seconds (a restart, a failover that takes its time) | `Retry` gives up after its attempts (by default 3, a few tens of milliseconds apart): the request fails with `500` and the `exhausted` counter goes up. How long the request *waits* before that is up to the connection layer, and a request that waits holds a worker. | Short waits in the pooler: for PgBouncer `query_wait_timeout` (default 120 s) and `server_login_retry` (default 15 s: after a failed login the pooler answers with the cached error for that long). In the demo's load test (1,500 requests per second, the pooler and the database restarted) 3 to 4 seconds and 1 second instead of the defaults cut the failed requests from 3.5% to 0.1% and the slowest request from 15 s to 4 s. |
| A transaction is left open by a request | Rolled back, logged and counted (`db_transactions_leaked_total`). | |
| The identity provider is down | Keys already loaded keep working; a worker that starts with none waits briefly for the first document, then refuses with `401` (closed, not open). | `warmUp()` at boot; `cacheSeconds` for how long a token that was verified is trusted. |
| The process restarts | Counters in APCu start again (Prometheus copes with a counter that resets); the verified-token cache and the keys are loaded again; the compiled container is read from disk. | |

Not in the core, on purpose: durable retries of failed work (an outbox, a broker, a sweeper: see [Reliable processing](reliable-processing.md)), circuit breakers and retries of outgoing HTTP calls, load shedding.

