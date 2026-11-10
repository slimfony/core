# Stability and support

slimfony/core follows [Semantic Versioning](https://semver.org). From 1.0 on, a breaking change to what is listed here is a new major version. Before 1.0 the minor version may break it (see [UPGRADE.md](UPGRADE.md)), and every such change is in the CHANGELOG.

## What is covered

- **The classes marked `@api`** in `src/`: their public constructors, methods, properties and constants. Constructor parameters are called by name in practice (`new Retry($connection, maxAttempts: 5)`), so **the names of parameters are part of the API**; adding a parameter at the end, with a default, is not a break.
- **The contracts that are not classes:**
  - the *metrics the core creates itself*: `http_requests_total{method,route,status}` and `http_request_duration_seconds{method,route}` (the other counters, such as the retry counters, are made and named by you; the core only fixes their label: its values are `Retry::REASON_*`);
  - the *fields of a log line* (`ts`, `level`, `message`, `context`, `request_id`, `trace_id`, `span_id`);
  - the *shape of an error response*: RFC 9457 `application/problem+json` with `type`, `title`, `status`, `detail` (when there is one) and `request_id`;
  - the *request attributes* of `Http\Attributes` and the route defaults (`_controller`, `_public`, `_scopes`, `_idempotent`);
  - the *environment variables and files* the core reads (`Config\Env`, `Config\Trust`: `NAME_FILE`, `TRUSTED_PROXIES`, `TRUSTED_HOSTS`);
  - the command `vendor/bin/slimfony warmup` and its arguments.

## What is not covered

- Everything marked `@internal`, and everything not marked `@api`.
- The constructors of `Counter`, `Gauge` and `Histogram` (make them with `MetricsRegistry`).
- The wording of log messages and of exception messages (match on the exception class, or on the codes and fields above).
- The layout of what the core stores for itself: APCu keys, the files of the compiled container and route cache (run `warmup` again after an upgrade), the table of `DbalIdempotencyStore` beyond what `TABLE_SQL` creates.
- Performance figures (the benchmark guard in CI protects them, but they are not a promise).
- `tests/Support` and `benchmarks/`.

## Deprecation

Something that is going to be removed is first marked `@deprecated` (with what to use instead) and kept for at least one minor version, and the CHANGELOG says so. Before 1.0 a removal may come without that notice, but it is always in the CHANGELOG and in UPGRADE.md.

## Supported versions

| | |
|---|---|
| PHP | 8.4 and 8.5 (both run in CI). A PHP version is dropped in a minor release when it reaches its end of life; the CHANGELOG announces it one minor version ahead. |
| Symfony components | `^8.1` (`http-foundation`, `routing`, `dependency-injection`). |
| Doctrine DBAL | `^4.5`; PostgreSQL is the database the retries and the failover handling are written and tested for. |
| FrankenPHP | worker mode and classic mode; the demo runs 1.13. |

Security fixes go to the latest release. See [SECURITY.md](SECURITY.md) for how to report a vulnerability.