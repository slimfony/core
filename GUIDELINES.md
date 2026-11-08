# Guidelines

Binding rules for every change. If a change breaks one, it needs a written reason in the PR.

## 1. Dependencies (slim vendor)
- **Deployment model:** slimfony/core is the base of our own microservices, not a shared library for third-party apps. Each service owns its whole dependency tree and commits its own `composer.lock`. So there is no compatibility matrix: only PHP >= 8.4 and the **latest stable** release of every package (runtime and dev). Constraints are floors at the current release (`^MAJOR.MINOR` of what is installed). When choosing a constraint, check `composer outdated` / the package page; never copy versions from memory or old plans.
- `composer.lock` is not committed here (CI always resolves the newest); services commit theirs. The core itself `replace`s the polyfills that PHP 8.4 makes redundant (`ctype`, `mbstring`, `php80`, `php81`, `php84`) and that applies to every service that installs it, so a service must not repeat the block (two packages replacing the same polyfill cannot coexist).
- A new package in `require` needs a justification in the PR: what it does, why ~50 lines of our own code or a PHP built-in do not.
- Prefer, in order: PHP built-in -> already-installed package -> own code -> new package.
- No polyfills (PHP >= 8.4). Required extensions are declared as `ext-*`.
- No dependency for: logging (own PSR-3 JSON logger), UUID/request-id (`random_bytes`), JSON (`json_encode` + `JSON_THROW_ON_ERROR`), env/config (own `Env`), HTTP client, validation, serialization, cache abstraction.
- Allowed runtime packages: `symfony/{http-foundation,routing,dependency-injection,service-contracts}`, `doctrine/dbal`, `psr/log`. An optional integration (JWT) lives in its own namespace and uses a package from `suggest`: it fails at boot with a `ConfigurationException` when the package is missing, and `tests/DeclaredDependenciesTest.php` makes sure nothing else uses it. Everything else is an application concern.
- `composer audit` and `composer validate --strict` must pass in CI. A weekly workflow runs `composer outdated --direct --strict`; an outdated package is fixed in the next change, not ignored.

## 2. Speed
- Nothing is computed per request that can be computed at build/boot: container and router are dumped by `bin/slimfony warmup`, middleware chain is built once.
- No reflection, no autowiring, no event dispatching on the hot path. Controllers: constructor injection + `__invoke(Request): Response`.
- No I/O per request that is not the request's job: no ping, no config file reads, no network calls for keys/config (cache with TTL in APCu).
- Every hot-path change is checked with `composer bench`; the goal is a p95 below 1 ms and CI guards it at 250 µs, so a real regression (reflection, I/O on the hot path) fails the build long before the goal is at risk.

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
- PHPStan `level: max` + strict rules, no baseline; PER-CS and `@PHP8x4Migration` (both including their risky sets) via php-cs-fixer, plus strict comparison and params, Yoda conditions, static lambdas, ordered imports and native function/constant invocation.
- Modern PHP 8.4 style: typed class constants, `#[\Override]` on every implemented/overridden method, asymmetric visibility (`public private(set)`) instead of public mutable properties, `readonly` value objects, `new Foo()->bar()` without extra parentheses. Names that cross class boundaries (request attributes, container parameters) are constants, never repeated string literals.
- Property hooks for values that are validated or computed: a setting with a valid range (`maxAttempts >= 1`, limits `>= 0`, an HTTP error status of 4xx/5xx) validates itself in a `set` hook, so nonsense fails when the object is built at boot. Class constants always carry a native type (checked by `ArchitectureTest`).
- Objects over static helpers: collaborators are injected instances; `static` only for pure functions on their arguments and named constructors. The one exception is `Config\Trust`, which writes Symfony's static request state once at boot.
- One responsibility per class (e.g. logger vs. context sanitizer, kernel vs. error responder). `tests/ArchitectureTest.php` enforces `strict_types`, `final` and `#[\Override]` for everything in `src/`.
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
| AuthN / AuthZ | Core (`Authenticator`, `Principal`, `AuthMiddleware`, `_public`, `_scopes`) and `Auth\Jwt` (verify-only JWT, optional `firebase/php-jwt`) | resource server: it never issues tokens |
| **Session** | **Not in the core** | Services are stateless: identity travels in the token, state lives in the database. Server-side sessions would break horizontal scaling and are a leak risk in worker mode. |
| Cache | APCu directly where needed (JWKS, readiness) | no PSR-6/16 layer until a real need appears |
| Async / messaging | M2: transactional outbox + NATS | not the core's job; kernel is CLI-bootable for relays/consumers |
| Outbound HTTP, validation, serialization | Application's choice | not core |
| Rate limiting, CORS, TLS | Gateway / ingress / Caddy | core only exposes the middleware seam |

## 6. Definition of done (binding for every change, by humans and AI assistants alike)
A change is not done until all of this is true:

1. `composer ci` exits 0 (phpunit, phpstan max, php-cs-fixer). Check the exit code, not the output text. Never commit a red tree; after pushing, the GitHub Actions run must be green too.
2. A new library class is `final`, has `declare(strict_types=1)`, carries `@api` or `@internal`, types everything, uses `#[\Override]` where it implements or overrides, and gets its collaborators injected. Errors the core raises on purpose come from the exception hierarchy: `ConfigurationException` (bad configuration, found at boot), `BuildException` (build-time step failed), `HttpException` (a deliberate HTTP error); all implement `SlimfonyException`. `tests/ArchitectureTest.php` enforces most of this.
3. A setting with a valid range validates itself in a property hook, so nonsense fails when the object is built at boot.
4. Tests: behaviour only, few, deterministic, safe in random order. Use the helpers in `tests/Support` (`TestKernel`, `TempDir`, `RecordingLogger`, `TestDb`) instead of copying their code. Anything touching PostgreSQL, APCu or FrankenPHP is proven in CI against the real thing.
5. Dependencies: every package at its latest stable release, checked with `composer outdated` and never from memory. A new `require` needs the justification from section 1.
6. Docs: a change in public behaviour updates the README (its code samples are executed by `tests/ReadmeTest.php`) and the CHANGELOG.
7. Secrets: never in code, logs, the compiled cache or the repository.
