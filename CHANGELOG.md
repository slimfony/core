# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

### Added
- `WorkerRunner` `deferGc`: the cycle collector is off while a request is handled and runs between requests (when `gcRootsThreshold` possible cycles have piled up), so no response pays for it. Optional counters `gcRuns` and `gcCollected`.
- `Observability\ProcessMetricsEndpoint`: lets Prometheus read the metrics of a command line process (relay, consumer) over a tiny non-blocking `GET /metrics` listener, polled between units of work; access is decided by `MetricsController`.
- `Retry` counts the operations that failed after all attempts in an optional second counter (`exhausted`, same `reason` label), and the pause between attempts is configurable (`backoffMicros`).
- `JwksKeyProvider::warmUp()`: loads the keys at worker start.

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
