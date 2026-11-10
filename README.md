<img src="assets/logo.svg" alt="slimfony logo" width="96" align="right">

# slimfony/core

[![Packagist](https://img.shields.io/packagist/v/slimfony/core.svg)](https://packagist.org/packages/slimfony/core)
[![CI](https://github.com/slimfony/core/actions/workflows/ci.yml/badge.svg)](https://github.com/slimfony/core/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/slimfony/core/graph/badge.svg)](https://codecov.io/gh/slimfony/core)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com/releases/8.1)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A small, strict core for PHP microservices on **FrankenPHP worker mode**. It is built from a few Symfony components, compiled once at build time, and keeps no state between requests.

> **Status:** v0.7.0, pre-1.0. What is covered by SemVer, and what is not, is in [STABILITY.md](STABILITY.md); before 1.0 even that may change in a minor version, and [UPGRADE.md](UPGRADE.md) says what each change asks of your code.

**Contents**

- [Why](#why)
- [When to use it](#when-to-use-it)
- [Demo](#demo)
- [Quickstart](#quickstart)
- [Guide](#guide)
- [What is in the box](#what-is-in-the-box)
- [Performance](#performance)
- [Roadmap](#roadmap)
- [Development](#development)
- [License](#license)

## Why

- **Fast:** nothing is computed per request that can be done at build or boot (container, router, middleware chain).
- **Small:** no YAML, no event dispatcher, no polyfills, PHP 8.4+. Runtime dependencies: `http-foundation`, `routing`, `dependency-injection`, `doctrine/dbal`, `psr/log`. JWT verification additionally uses `firebase/php-jwt`, only when you use it.
- **Secure by default:** no leaked errors, validated request ids, security headers, body size limit, nothing trusted until configured.
- **Worker-safe:** services are reset after every request; if a reset fails the worker stops.

It is the base for our own services, not a library for arbitrary apps: every service owns its dependency tree and commits its own `composer.lock`. Rules and scope: [GUIDELINES.md](GUIDELINES.md).

## When to use it

It fits a small service that does one job, runs for a long time, and has to be fast, observable and hard to misuse:

- **A resource server behind a gateway.** An orders, catalogue or pricing API that accepts bearer tokens from your identity provider (Keycloak, Entra, Auth0, or one you run), checks scopes per route, and answers from PostgreSQL. Tokens are verified once and remembered, a key rotation at the provider is picked up without a restart, and `/healthz`, `/readyz` and a protected `/metrics` come with it.
- **An intake endpoint that must not lose anything.** A webhook receiver or an upload API that answers `202` as soon as the request is stored, and does the real work later: the request and its message are written in one transaction (an outbox), and workers take them from there. The [demo](https://github.com/slimfony/demo) is exactly this: bank statements arrive as ZIP files and are booked by importers, even when one of them is killed in the middle.
- **A queue consumer or a scheduled job that shares the service's code.** The relay, the importer or the nightly job boots the same container as the web workers (`Kernel::get()`), is reset after every message, stops on a memory limit or a signal, and shows its metrics to Prometheus (`ProcessMetricsEndpoint`), without a second framework.
- **Many small services with one set of rules.** When a team runs a dozen services, the same logging, headers, error format, metrics and reset behaviour in every one of them (and one code style, which `tests/ArchitectureTest.php` checks) are worth more than the freedom to differ.

It is a poor fit where you want the whole Symfony ecosystem (forms, templates, an ORM, bundles, Messenger) or a content-driven site: this core has no argument resolver for controllers, no event dispatcher and no bundles, on purpose. A service that outgrows it should move to Symfony, not stretch the core.

## Demo

[slimfony/demo](https://github.com/slimfony/demo) is a complete service built on this core: FrankenPHP in worker mode, PostgreSQL behind PgBouncer, NATS, Prometheus and Grafana, started with one `docker compose up`. It shows the core running under load, with live metrics, health checks and the production container settings.

## Quickstart

Install it into your service:

```
composer require slimfony/core
```

The core replaces the polyfill packages that PHP 8.4 makes redundant (`ctype`, `mbstring`, `php80`, `php81`, `php84`), so your service gets a slim `vendor/` without doing anything; do not add a `replace` block for them yourself.

Map your application namespace in the service's `composer.json`:

```json
{
    "autoload": { "psr-4": { "App\\": "src/" } }
}
```
`src/Hello.php` is a controller: an invokable service.

```php
<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Hello
{
    public function __invoke(Request $request): Response
    {
        return new Response('hello ' . $request->attributes->getString('name'));
    }
}
```

`config/services.php` registers services and the middleware chain (outermost first):

```php
<?php

declare(strict_types=1);

use App\Hello;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Worker\ResetPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    // created in public/index.php (the logger is needed before the container exists) and handed over as synthetic services
    $c->register(RequestContext::class)->setSynthetic(true)->setPublic(true)->addTag(ResetPass::TAG);
    $c->register(LoggerInterface::class)->setSynthetic(true)->setPublic(true);

    $c->register(RequestIdMiddleware::class)->setAutowired(true);
    $c->register(SecurityHeadersMiddleware::class);
    $c->setParameter('slimfony.middleware', [RequestIdMiddleware::class, SecurityHeadersMiddleware::class]);

    $c->register(Hello::class);
};
```

`config/routes.php` maps routes to controller services:

```php
<?php

declare(strict_types=1);

use App\Hello;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('hello', new Route('/hello/{name}', ['_controller' => Hello::class], methods: ['GET']));
};
```

`public/index.php` is the worker script:

```php
<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Config\Trust;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\JsonStdoutLogger;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Worker\WorkerRunner;

require __DIR__ . '/../vendor/autoload.php';

$env = Env::fromGlobals();
new Trust($env)->apply(); // TRUSTED_PROXIES / TRUSTED_HOSTS; nothing is trusted by default

$context = new RequestContext();
$logger = new JsonStdoutLogger($env->string('LOG_LEVEL', 'info'), $context);

$kernel = new Kernel(
    __DIR__ . '/../config/services.php',
    __DIR__ . '/../config/routes.php',
    debug: $env->bool('APP_DEBUG'),
    logger: $logger,
    cacheDir: __DIR__ . '/../var/cache', // written by the warmup below; compiled at boot when missing
    synthetic: [RequestContext::class => $context, LoggerInterface::class => $logger],
);

new WorkerRunner($kernel, $logger, maxRequests: $env->int('MAX_REQUESTS', 0))->run();
```

Run it with FrankenPHP, `Caddyfile`:

```
{
	frankenphp {
		worker {
			file /app/public/index.php
			num 4
			env MAX_REQUESTS 500
		}
	}
}

:8080 {
	root * /app/public
	php_server
}
```

Without FrankenPHP the runner serves a single request, so the same `index.php` works as a classic front controller.

## Guide

The chapters of the guide are separate pages:

- [Production](guide/production.md): Compile at build time, the environment variables, secrets.
- [Database](guide/database.md): PostgreSQL through DBAL: the connection across requests, bounded retries, failover.
- [Metrics and health](guide/metrics-and-health.md): Prometheus metrics, the protected `/metrics`, `/healthz` and `/readyz`.
- [Authentication](guide/authentication.md): Bearer tokens: deny by default, scopes, JWT verification, the identity provider's keys.
- [Consumers and jobs (without HTTP)](guide/consumers.md): Queue consumers, relays and jobs that share the service's code and metrics.
- [Reliable processing](guide/reliable-processing.md): The outbox, leases, idempotent handlers, the sweeper, `Idempotency-Key`, deadlocks.
- [Failure behaviour and restarts](guide/failure-behaviour.md): What happens when something goes wrong, and what is left to the platform.
- [Sizing](guide/sizing.md): Workers per core, garbage collection, classic mode.
- [Worker mode pitfalls](guide/worker-mode-pitfalls.md): State between requests, sessions, bodies, database connections.

## What is in the box

| Component | What it does |
|---|---|
| `Kernel` | Boots once, routes, runs controllers, turns errors into `application/problem+json` (400, 404, 405 with `Allow`, 413, 500). |
| `Http\RequestIdMiddleware`, `SecurityHeadersMiddleware`, `BodySizeLimitMiddleware` | Request id, trace id, a span id of its own and the `traceparent` for outgoing calls (forged values replaced), secure response headers, 413 on a too large body (declared or chunked). |
| `Worker\WorkerRunner` | The worker loop: max requests, memory limit, optional GC, stops when a reset fails. |
| `Worker\ConsumerRunner` | The loop of a non-HTTP process (queue consumer, relay, job): reset after every message, message and memory limits, stop on SIGTERM. |
| `Build\ContainerCompiler`, `CompilationCache` | Compile the configuration and dump it at build time (`vendor/bin/slimfony warmup`). |
| `Log\JsonStdoutLogger` | One JSON object per line on stdout, tagged with request id and trace id; secrets are redacted. |
| `Config\Env`, `Config\Trust` | Typed environment access with `NAME_FILE` secrets; trusted proxies and hosts. |
| `Observability\*` | Counters and histograms in shared APCu memory, Prometheus text format, protected `/metrics`, `MetricsMiddleware`. |
| `Health\*` | `/healthz` and `/readyz` controllers and the `ReadinessCheck` interface. |
| `Auth\*` | `Authenticator` contract, `Principal`, `AuthMiddleware` (deny by default, `_public` and `_scopes` route defaults), and `Auth\Jwt\*`: verification of bearer JWTs. |
| `Database\*` | Connection factory, lifecycle (leaked transactions, idle connections), bounded retries (deadlocks, lost connections, failover). |
| `Idempotency\*` | `IdempotencyMiddleware`, the `IdempotencyStore` contract and its database implementation: `Idempotency-Key` for `POST` and `PATCH`. |

## Performance

`composer bench` measures what the core itself adds to a request: `Kernel::handle()` with a typical production chain (metrics in APCu, request id, security headers, body limit, authentication), one request at a time, as latency percentiles. It excludes HTTP parsing and the network (Caddy/FrankenPHP) and your own application code. The goal is a p95 below 1 ms. CI fails if any scenario's p95 exceeds 250 µs and prints the table in the job summary.

Results on the CI runner (Linux, PHP 8.4, opcache and JIT on, 30 000 requests per scenario), in microseconds:

| Scenario | p50 | p95 | p99 |
|---|---:|---:|---:|
| `GET /healthz` (public route) | 28.8 | 40.3 | 51.2 |
| `GET /hello/{name}` (authenticated, scope check, JSON response) | 34.1 | 44.3 | 56.5 |
| `GET /unknown` (404 through the chain) | 34.4 | 44.0 | 55.8 |
| RS256 JWT, token remembered (default) | 35.7 | 46.5 | 58.4 |
| ES256 JWT, token remembered (default) | 35.4 | 46.3 | 57.4 |
| EdDSA JWT, token remembered (default) | 35.3 | 46.9 | 58.9 |
| RS256 JWT, signature verified on every request | 129.2 | 151.1 | 168.6 |
| ES256 JWT, signature verified on every request | 197.4 | 222.4 | 245.1 |
| EdDSA JWT, signature verified on every request | 116.7 | 136.8 | 152.2 |

- The core's own work (routing, five middleware, controller, response) stays below 50 µs at p95, twenty times below the goal.
- With JWT authentication the default costs the same as no authentication: a token that verified is remembered for 30 seconds.
- Verifying the signature on every request (`cacheSeconds: 0`) makes the whole request take 137 µs (EdDSA), 151 µs (RS256) or 222 µs (ES256) at p95. The signature check is the cost, not the core.

Other environments, p95 in microseconds:

| Environment | public route | authenticated JSON | 404 |
|---|---:|---:|---:|
| Faster CI runner (Linux, PHP 8.4, opcache and JIT) | 18.5 | 21.2 | 20.7 |
| Windows 11 development machine (PHP 8.4, opcache and JIT) | 34.6 | 40.2 | 40.2 |

The same scenarios without opcache (the CLI default) are two to three times slower. Runner hardware varies, which is why the CI guard sits at 250 µs, ten times the usual result. These numbers are the overhead of the core, not an end-to-end throughput claim; end-to-end load tests with k6 belong to the demo.
## Roadmap

1. **[slimfony/demo](https://github.com/slimfony/demo)** is released: a complete reference service on the core with Docker, PostgreSQL, NATS (transactional outbox, importers), Prometheus, Grafana, Kubernetes manifests and published k6 load results. A small starter template without the demo's domain is planned.

## Development

```
composer ci       # phpunit + phpstan (max) + php-cs-fixer
composer bench    # per-request latency of the core (needs APCu with apc.enable_cli=1)
```

The code rules are enforced by the tests; see [GUIDELINES.md](GUIDELINES.md), and [CONTRIBUTING.md](CONTRIBUTING.md) for how to make a change. The code samples in this README are executed by `tests/ReadmeTest.php`, so they cannot rot.

## License

MIT
