# slimfony/core

Ultra-lightweight, secure, Symfony-component based microservice core for **FrankenPHP worker mode**. Cloud native (Kubernetes) and on-prem friendly.

> Status: **pre-alpha**, API is not stable.

## Goals

- Minimal per-request overhead, no state leaking between requests.
- No YAML: configuration is plain PHP arrays/closures.
- Small supply chain: see [GUIDELINES.md](GUIDELINES.md) (rules, and what the core handles: DB, logging, metrics, health, auth contract; sessions deliberately not).

## Roadmap

- v0.1: Kernel + middleware chain, worker runner, state resetter, DBAL connection lifecycle, Prometheus metrics, health endpoints, auth contract.
- v0.2: `slimfony/auth-jwt` (optional JWT verification).
- M2: transactional outbox + NATS integration (async is not the core's job).

## License

MIT

## Slim vendor

PHP >= 8.4 only, so no `symfony/polyfill-php8x`. The core requires `ext-mbstring` and `ext-ctype`; the repo `replace`s their polyfills and your service should do the same in its own `composer.json`:

```json
"replace": { "symfony/polyfill-mbstring": "*", "symfony/polyfill-ctype": "*", "symfony/polyfill-php80": "*", "symfony/polyfill-php81": "*", "symfony/polyfill-php84": "*" }
```
