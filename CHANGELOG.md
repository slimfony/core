# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

### Changed
- **Breaking: `WorkerRunner` takes its garbage-collection settings as one object.** The parameters `gcEvery`, `deferGc`, `gcRootsThreshold`, `gcRuns` and `gcCollected` are replaced by `gc: new GarbageCollection(every:, deferred:, rootsThreshold:, runs:, collected:)` (see UPGRADE.md). The constructor had eleven parameters, five of them for one concern; the parameter names are part of the API, so this is the time to group them.
- The constructors of `Counter`, `Gauge` and `Histogram` are marked `@internal`: make them with `MetricsRegistry`.

### Documentation
- **The README is shorter, the chapters are in `guide/`** (production, database, metrics and health, authentication, consumers, reliable processing, failure behaviour, sizing, worker mode pitfalls). The README keeps the idea, the quickstart, the overview and the performance figures. The tests run the code samples of the guide and check that every link to a file works.
- `STABILITY.md` (what SemVer covers: the `@api` classes and the contracts that are not classes; what it does not; the deprecation rule; the supported versions), `UPGRADE.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, issue and pull request templates.

## [0.5.0] - 2026-11-10

### Added
- **`Idempotency\IdempotencyMiddleware`**: `Idempotency-Key` for the routes marked `_idempotent: true` (`POST` and `PATCH`). The first answer is kept and sent again for the same request (`Idempotent-Replayed: true`); a different request with the same key is `422`, the same one while the first runs is `409` with `Retry-After`; a `5xx` or an exception is not kept; keys belong to the caller. The `IdempotencyStore` contract has a database implementation (`DbalIdempotencyStore`, `TABLE_SQL`); the request attribute `Attributes::IDEMPOTENCY_KEY` lets a handler guard its own effect with a unique column.
- **Tracing context.** `RequestIdMiddleware` gives every request a span id of its own (`Attributes::SPAN_ID`, logged as `span_id`) and an outgoing `traceparent` (`Attributes::TRACEPARENT`): the same trace, this request's span as the parent, the sampling flags of the caller (a new trace is sampled). Put it on the calls the service makes and the next service continues the trace; an OpenTelemetry SDK can export the spans.
- `Retry` handles a database failover: a server that refuses the work because it is read-only (SQLSTATE 25006, the old primary or a standby) or still starting up (57P03) is retried on a new connection, for any work, idempotent or not (the statement was refused before it ran). Counted with the reason `failover`. A connection that could not be made (`ConnectionException`) is now treated like a lost one.

### Changed
- **`BodySizeLimitMiddleware` limits a body without a declared length too** (a chunked body, whose size the web server does not announce): it is read up to the limit plus one byte, and answered with 413 beyond that. Before, only a declared `Content-Length` was checked.
- **Log messages mask `Bearer`, `Basic` and JWT values** wherever they stand in a text, not only passwords inside URLs.
- `Histogram` no longer stores a separate count: it is the sum of the buckets when the metrics are rendered (one APCu write less per observation, and the cumulative buckets can never be ahead of the count within one scrape). Not measurable in the benchmark, so it is a simplification, not a speed-up. A count key that an older version left in APCu is ignored.

### Fixed
- `JwksKeyProvider` and `CompilationCache` no longer let PHP write a warning for a failure they already report by an exception (an unreachable key provider, a cache directory that cannot be created).

### Documentation
- README "Failure behaviour and restarts": what the core does when something goes wrong (a table of the cases), what is left to the platform (timeouts, load shedding, the graceful shutdown of a pod) and what the core leaves out on purpose.
- README "When to use it": the kinds of service the core is for (a resource server, an intake endpoint with an outbox, a consumer that shares the service's code, many small services with one set of rules) and what it is not for.
- README "Sizing": the garbage collector never ran in the demo's load, with or without `deferGc`; it is insurance for code that builds reference cycles.

## [0.4.0] - 2026-11-09

### Added
- `WorkerRunner` `deferGc`: the cycle collector is off while a request is handled and runs between requests (when `gcRootsThreshold` possible cycles have piled up), so no response pays for it. Optional counters `gcRuns` and `gcCollected`.
- `Observability\ProcessMetricsEndpoint`: lets Prometheus read the metrics of a command line process (relay, consumer) over a tiny non-blocking `GET /metrics` listener, polled between units of work; access is decided by `MetricsController`.
- `Retry` counts the operations that failed after all attempts in an optional second counter (`exhausted`, same `reason` label), and the pause between attempts is configurable (`backoffMicros`).
- `JwksKeyProvider::warmUp()`: loads the keys at worker start.

### Documentation
- README: chapters on reliable processing (outbox, leases, idempotent handlers, sweeper, deadlock prevention) and sizing (workers per core, garbage collection, classic mode).

### Fixed
- `JwksKeyProvider`: a worker that starts while another thread fetches the first JWKS document waits for it (`coldStartWaitSeconds`, 3 s) instead of failing its first requests with 401.
- `WorkerRunner` in FrankenPHP's classic mode (no worker script): `frankenphp_handle_request()` exists there and throws, so the runner now serves the one request instead of failing.

## [0.3.0] - 2026-11-05

### Added
- `Kernel::get()`: a public service of the container for code that runs without an HTTP request.
- `Worker\ConsumerRunner`: the loop of a non-HTTP process (reset after every message, message and memory limits, stop on SIGTERM or SIGINT).
- `Observability\Gauge` and `MetricsRegistry::gauge()`.
- `Retry` counts its retries in an optional counter (`db_retries_total`, label `reason`).

### Changed
- `Observability\Storage` gains `set()`; custom implementations must add it.

## [0.2.0] - 2026-11-05

### Added
- JWT bearer authentication (`Auth\Jwt\*`): `JwtAuthenticator`, `JwtConfiguration`, `JwksKeyProvider` (JWKS shared in APCu), `StaticKeyProvider`. Optional dependency `firebase/php-jwt`; a token that verified is remembered for 30 seconds.
- Benchmark scenarios with real RS256, ES256 and EdDSA tokens.
- Logo, avatar and social preview image (`assets/`).

## [0.1.0] - 2026-11-05

First tagged release: the foundation of the core. Pre-1.0: only classes marked `@api` are covered by SemVer, and they may still change.

### Added
- `Kernel`: compiled router and container, middleware chain, `application/problem+json` errors (400, 404, 405, 413, 500), synthetic services.
- Middleware: `RequestIdMiddleware` (request id and trace id), `SecurityHeadersMiddleware`, `BodySizeLimitMiddleware`.
- `WorkerRunner` and `StateResetter`: FrankenPHP worker loop with max requests, memory limit, fail-fast on reset errors.
- Build-time warmup: `vendor/bin/slimfony warmup` (`ContainerCompiler`, `CompilationCache`).
- `Config\Env` (typed, `NAME_FILE` secrets) and `Config\Trust` (trusted proxies and hosts).
- `Log\JsonStdoutLogger` with request correlation and secret redaction.
- Database: `ConnectionFactory`, `ConnectionLifecycle` (leaked transactions, idle connections), `Retry`.
- Observability: `MetricsRegistry` with boot-time declared `Counter`/`Histogram`, `ApcuStorage`, `MetricsMiddleware`, protected `MetricsController` (Prometheus text format).
- Health: `LivenessController` (`/healthz`), `ReadinessController` (`/readyz`, cached), `ReadinessCheck`, `DatabaseReadinessCheck`.
- `ConnectionLifecycle` can count leaked transactions (`db_transactions_leaked_total`).
- Auth: `Authenticator` contract, `Principal`, `AuthMiddleware` (deny by default, `_public`, `_scopes`). The Kernel now matches the route before the middleware chain runs, so middleware sees the matched route and its defaults; routing failures are still answered through the chain.
- `composer bench`: per-request latency percentiles of the core; CI guards the p95 at 250 µs (goal: below 1 ms).
- Exception hierarchy: `SlimfonyException`, `ConfigurationException`, `BuildException`, `HttpException`.
- CI: PHP 8.4 and 8.5, real PostgreSQL, FrankenPHP worker smoke test, Codecov, weekly outdated check.
- Quality gates: PHPStan level max, php-cs-fixer (PER-CS and PHP 8.4 risky sets), architecture tests (strict_types, final, `#[\Override]`, typed constants, `@api`/`@internal`, declared dependencies only), random-order tests, executable README samples.
- Supply chain: GitHub Actions pinned to commit SHAs, Dependabot, weekly outdated-dependency check, `composer audit` in CI.

[Unreleased]: https://github.com/slimfony/core/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/slimfony/core/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/slimfony/core/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/slimfony/core/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/slimfony/core/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/slimfony/core/releases/tag/v0.1.0
