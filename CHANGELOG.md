# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com), versioning: [SemVer](https://semver.org).

## [Unreleased]

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
- Exception hierarchy: `SlimfonyException`, `ConfigurationException`, `BuildException`, `HttpException`.
- CI: PHP 8.4 and 8.5, real PostgreSQL, FrankenPHP worker smoke test, Codecov, weekly outdated check.
