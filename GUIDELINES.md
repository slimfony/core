# Guidelines

Binding rules for every change. If a change breaks one, it needs a written reason in the PR.

## 1. Dependencies (slim vendor)
- A new package in `require` needs a justification in the PR: what it does, why ~50 lines of our own code or a PHP built-in do not.
- Prefer, in order: PHP built-in -> already-installed package -> own code -> new package.
- No polyfills (PHP >= 8.4). Required extensions are declared as `ext-*`.
- No dependency for: logging (own PSR-3 JSON logger), UUID/request-id (`random_bytes`), JSON (`json_encode` + `JSON_THROW_ON_ERROR`), env/config (own `Env`), HTTP client, validation, serialization, cache abstraction.
- Allowed runtime packages: `symfony/{http-foundation,routing,dependency-injection,service-contracts}`, `doctrine/dbal`, `psr/log`. Everything else is `suggest` or a separate package (`slimfony/auth-jwt`, ...).
- `composer audit` and `composer validate --strict` must pass in CI.

## 2. Speed
- Nothing is computed per request that can be computed at build/boot: container and router are dumped by `bin/slimfony warmup`, middleware chain is built once.
- No reflection, no autowiring, no event dispatching on the hot path. Controllers: constructor injection + `__invoke(Request): Response`.
- No I/O per request that is not the request's job: no ping, no config file reads, no network calls for keys/config (cache with TTL in APCu).
- Every hot-path change is checked with phpbench; the p95 < 1 ms claim must stay true.

## 3. Security (secure by default)
- `APP_DEBUG` defaults to false and comes only from env. Non-debug errors: generic `problem+json` + request id, never messages, traces, DSNs or SQL.
- Secrets only via env or `*_FILE`; never logged, never dumped into the compiled container (only `%env()%` placeholders).
- All external input is bounded: body size, JSON depth, header length; `X-Request-Id` / `traceparent` are validated, not trusted.
- Default response headers: `nosniff`, `no-store`, restrictive CSP. Trusted proxies/hosts are configured explicitly at boot; method override is off.
- Auth is deny-by-default once an `Authenticator` is configured; public routes opt out with `_public`.
- `/metrics` is off unless a token or CIDR allowlist is configured. Health endpoints expose no internals.
- No `unserialize`, `eval`, `extract`, `$$var`, `@` suppression, `shell_exec`.
- Types: every parameter, return and property has a native type; no `mixed` unless the value is genuinely opaque (and then narrowed at once). External input (query, body, headers, env, DB rows) is treated as `mixed` and narrowed with a strict typed accessor before use; no implicit casts, no loose `==`.
- SQL: always bound parameters (`:name` / `?` with explicit DBAL types); never string concatenation or interpolation of values. Our own DB helpers take `literal-string` SQL, so PHPStan max rejects dynamic SQL at analysis time. Dynamic identifiers (table, column, ORDER BY) only from a hard-coded allowlist.

## 4. Worker mode (no state leaks)
- No mutable `static`, no globals, no singletons outside the container. Request-scoped state lives in `Request->attributes` or in a service implementing `ResetInterface`.
- Everything that holds per-request state implements `Symfony\Contracts\Service\ResetInterface`; the resetter runs after every request. A failing reset terminates the worker (fail-fast).
- No `session_start()`, no `$_SESSION`, no setcookie-based state. See "Capabilities".
- DB: no persistent PDO, no session-level settings, open transactions are rolled back (and logged) at request end.

## 5. Code quality
- `declare(strict_types=1)`, `final` by default, `readonly` where possible, interfaces only at external boundaries (storage, authenticator, clock-like seams) - no interface with one implementation otherwise.
- PHPStan `level: max` + strict rules, no baseline; PER-CS via php-cs-fixer.
- Tests: few, real, deterministic. No coverage target. Every test pins a concrete business or security behaviour (e.g. a leaked transaction is rolled back, a failed reset kills the worker, 404 label is `unmatched`); no getter/constructor/mock-echo tests, no sleeps, no timing assertions, no test-order or network dependence. A flaky test is deleted or fixed the same day, never retried.
- Anything touching DB or APCu runs against the real thing in CI (PostgreSQL service, APCu enabled); SQLite / in-memory doubles are for fast local runs only.
- Public API changes are recorded in `CHANGELOG.md`; SemVer.

## Capabilities: what a microservice needs and what handles it

| Need | Handled by | Notes |
|---|---|---|
| HTTP, routing, DI | Core (`Kernel`, Symfony components) | compiled at build time |
| Database | Core (`Database\ConnectionLifecycle`, `retry/transactional`) + DBAL | PostgreSQL first; PgBouncer-safe options |
| Logging | Core (`Log\JsonStdoutLogger`, PSR-3) | JSON to stdout, request id / trace id in every line, secrets redacted; no Monolog needed |
| Metrics | Core (`Observability`, Prometheus text format) | APCu storage |
| Health | Core (`/healthz`, `/readyz`) | k8s and on-prem LB friendly |
| Config / secrets | Core (`Env`, `*_FILE`) | 12-factor, no YAML |
| Request identity / tracing | Core (request-id, W3C `traceparent` propagation) | no OTel SDK |
| AuthN / AuthZ | Core contract (`Authenticator`, `Principal`, `_public`, `_scopes`); JWT in `slimfony/auth-jwt` | verify-only resource server |
| **Session** | **Not in core, by design** | services are stateless: identity travels in the token, state lives in the DB. Server-side sessions would break horizontal scaling and are a leak risk in worker mode. If ever needed: separate opt-in package with an explicit store. |
| Cache | APCu directly where needed (JWKS, readiness) | no PSR-6/16 layer until a real need appears |
| Async / messaging | M2: transactional outbox + NATS | not the core's job; kernel is CLI-bootable for relays/consumers |
| Outbound HTTP, validation, serialization | Application's choice | not core |
| Rate limiting, CORS, TLS | Gateway / ingress / Caddy | core only exposes the middleware seam |
