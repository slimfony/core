<img src="assets/logo.svg" alt="slimfony logo" width="96" align="right">

# slimfony/core

[![CI](https://github.com/slimfony/core/actions/workflows/ci.yml/badge.svg)](https://github.com/slimfony/core/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/slimfony/core/graph/badge.svg)](https://codecov.io/gh/slimfony/core)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com/releases/8.1)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A small, strict core for PHP microservices on **FrankenPHP worker mode**. It is built from a few Symfony components, compiled once at build time, and keeps no state between requests.

> **Status:** v0.1, pre-1.0. Only classes marked `@api` are covered by SemVer, and even those may still change before 1.0.

## Why

- **Fast:** nothing is computed per request that can be done at build or boot (container, router, middleware chain).
- **Small:** no YAML, no event dispatcher, no polyfills, PHP 8.4+. Runtime dependencies: `http-foundation`, `routing`, `dependency-injection`, `doctrine/dbal`, `psr/log`.
- **Secure by default:** no leaked errors, validated request ids, security headers, body size limit, nothing trusted until configured.
- **Worker-safe:** services are reset after every request; if a reset fails the worker stops.

It is the base for our own services, not a library for arbitrary apps: every service owns its dependency tree and commits its own `composer.lock`. Rules and scope: [GUIDELINES.md](GUIDELINES.md).

## Quickstart

Not on Packagist yet; install from GitHub. In your service's `composer.json`:

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/slimfony/core" }],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": { "slimfony/core": "dev-main" },
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

## Production

Compile once when the image is built, not in every worker:

```
vendor/bin/slimfony warmup config/services.php config/routes.php var/cache
```

Workers then only `require` the dumped container and route table (shared by opcache). A broken configuration fails the warmup, so it never ships. Without a cache, or with `APP_DEBUG=1`, the Kernel compiles at boot, which is fine for development.

| Variable | Read by | Meaning |
|---|---|---|
| `APP_DEBUG` | `index.php` | `1` shows exception class and message in 500 responses. Default off. |
| `LOG_LEVEL` | `index.php` | Minimum log level, default `info`. |
| `MAX_REQUESTS` | `index.php` | Restart the worker after N requests, `0` = never. |
| `TRUSTED_PROXIES` | `Config\Trust` | Comma separated IPs/CIDRs of your ingress or load balancer. Without it `X-Forwarded-*` is ignored and the client IP is the ingress. |
| `TRUSTED_HOSTS` | `Config\Trust` | Comma separated exact host names; any other `Host` gets a 400. |
| `METRICS_TOKEN` | `MetricsController` | Bearer token for `/metrics`, at least 16 characters. |
| `<NAME>_FILE` | `Config\Env` | For any `NAME`: read the value from this file (Docker/Kubernetes secrets). |

Never put secret values into `services.php`: use `%env(NAME)%`, so the dumped cache only ever holds the placeholder.

## Database

PostgreSQL through DBAL, safe across requests in a long-lived worker. The pieces are plain objects, wire them in `index.php` and hand them to the container as synthetic services:

```php
<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionFactory;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;

$env = Env::fromGlobals();

$connection = ConnectionFactory::create($env->string('DATABASE_URL'), transactionPooling: $env->bool('PGBOUNCER'));
$lifecycle = new ConnectionLifecycle($connection, idleSeconds: 30); // register it as a synthetic service tagged with ResetPass::TAG
$retry = new Retry($connection);

// transient failures: the whole transaction is re-run on deadlock; a lost connection only with idempotent: true
$retry->transactional(static function (Connection $db): void {
    $db->executeStatement('UPDATE counters SET n = n + 1 WHERE id = ?', [1]);
}, idempotent: false);
```

`ConnectionLifecycle` rolls back (and logs) a transaction a request left open, and recycles a connection that has been idle too long. Add `ConnectionLifecycleMiddleware` to the chain to enable the idle check. All SQL uses bound parameters.

## Metrics and health

Prometheus metrics live in APCu, which all FrankenPHP worker threads share (needs `ext-apcu`; give it enough `apc.shm_size`). Values reset when the process restarts, which Prometheus' `rate()` handles. Create the registry in `public/index.php` and hand it over as a synthetic service:

```php
<?php

declare(strict_types=1);

use Slimfony\Core\Observability\ApcuStorage;
use Slimfony\Core\Observability\MetricsRegistry;

$metrics = new MetricsRegistry(new ApcuStorage());

// ... and add it to the Kernel's synthetic services: MetricsRegistry::class => $metrics
```

Add to `config/services.php` (put `MetricsMiddleware` first in `slimfony.middleware`, so it measures the whole request):

```php
<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Slimfony\Core\Health\DatabaseReadinessCheck;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Health\ReadinessController;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsMiddleware;
use Slimfony\Core\Observability\MetricsRegistry;
use Symfony\Component\DependencyInjection\Reference;

// inside the closure that receives the ContainerBuilder $c:
$c->register(MetricsRegistry::class)->setSynthetic(true)->setPublic(true);
$c->register(MetricsMiddleware::class)->setAutowired(true);
$c->register(LivenessController::class);
$c->register(ReadinessController::class)->setArguments([[new Reference(DatabaseReadinessCheck::class)], new Reference(LoggerInterface::class)]);
$c->register(MetricsController::class)->setArguments([new Reference(MetricsRegistry::class), '%env(METRICS_TOKEN)%']);
```

Add to `config/routes.php`:

```php
<?php

declare(strict_types=1);

use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Health\ReadinessController;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Observability\MetricsController;
use Symfony\Component\Routing\Route;

// inside the closure that receives the RouteCollection $r (PUBLIC matters once AuthMiddleware is in the chain):
$r->add('healthz', new Route('/healthz', ['_controller' => LivenessController::class, Attributes::PUBLIC => true], methods: ['GET']));
$r->add('readyz', new Route('/readyz', ['_controller' => ReadinessController::class, Attributes::PUBLIC => true], methods: ['GET']));
$r->add('metrics', new Route('/metrics', ['_controller' => MetricsController::class, Attributes::PUBLIC => true], methods: ['GET']));
```

- `/healthz` (liveness) never touches a dependency, so a database outage does not make Kubernetes restart healthy pods.
- `/readyz` (readiness) runs your `ReadinessCheck`s (`DatabaseReadinessCheck` is included), caches the result for 2 seconds and names failed checks without their error text.
- `/metrics` refuses to be constructed unprotected: pass a bearer token (16+ characters) and/or a list of allowed client IPs/CIDRs. Nothing is exposed unless you register the route.
- The built-in series are `http_requests_total{method,route,status}` and `http_request_duration_seconds{method,route}` (sub-millisecond buckets). The route label is the route name, never the raw URL. Add your own with `$registry->counter(...)` and `$registry->histogram(...)` at boot; `ConnectionLifecycle` accepts a counter for `db_transactions_leaked_total`.
## Authentication

The core defines the contract; verifying credentials is up to an `Authenticator` (a JWT verifier will ship as the separate, optional `slimfony/auth-jwt`; an identity header from a trusted gateway works too):

```php
<?php

declare(strict_types=1);

use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Principal;
use Symfony\Component\HttpFoundation\Request;

final class TokenAuthenticator implements Authenticator
{
    public function __construct(private readonly TokenVerifier $verifier) {} // your verifier: signature, issuer, audience, expiry

    #[\Override]
    public function authenticate(Request $request): ?Principal
    {
        $claims = $this->verifier->verify((string) $request->headers->get('Authorization')); // null when not valid

        return null === $claims ? null : new Principal($claims['sub'], $claims['scopes'], $claims);
    }
}
```

Put `AuthMiddleware` in the chain (after `RequestIdMiddleware`) and register your authenticator as the `Authenticator` service. From then on **everything is denied by default**: a request without a valid caller gets 401, also on paths that match no route (so the API cannot be mapped by its 404s). Routes opt out or demand scopes through their defaults:

```php
<?php

declare(strict_types=1);

use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Http\Attributes;
use Symfony\Component\Routing\Route;

// inside the closure that receives the RouteCollection $r:
$r->add('healthz', new Route('/healthz', ['_controller' => LivenessController::class, Attributes::PUBLIC => true]));
$r->add('orders', new Route('/orders', ['_controller' => OrdersController::class, Attributes::SCOPES => ['orders:read']]));
```

A caller that lacks any of the listed scopes gets 403. Controllers of protected routes read the caller with `Principal::from($request)`. Mark `/healthz`, `/readyz` and `/metrics` as `Attributes::PUBLIC` (the metrics controller has its own token or IP protection).
## What is in the box

| Component | What it does |
|---|---|
| `Kernel` | Boots once, routes, runs controllers, turns errors into `application/problem+json` (400, 404, 405 with `Allow`, 413, 500). |
| `Http\RequestIdMiddleware`, `SecurityHeadersMiddleware`, `BodySizeLimitMiddleware` | Request id and trace id (forged values replaced), secure response headers, 413 on a too large `Content-Length`. |
| `Worker\WorkerRunner` | The worker loop: max requests, memory limit, optional GC, stops when a reset fails. |
| `Build\ContainerCompiler`, `CompilationCache` | Compile the configuration and dump it at build time (`vendor/bin/slimfony warmup`). |
| `Log\JsonStdoutLogger` | One JSON object per line on stdout, tagged with request id and trace id; secrets are redacted. |
| `Config\Env`, `Config\Trust` | Typed environment access with `NAME_FILE` secrets; trusted proxies and hosts. |
| `Observability\*` | Counters and histograms in shared APCu memory, Prometheus text format, protected `/metrics`, `MetricsMiddleware`. |
| `Health\*` | `/healthz` and `/readyz` controllers and the `ReadinessCheck` interface. |
| `Auth\*` | `Authenticator` contract, `Principal`, `AuthMiddleware`: deny by default, `_public` and `_scopes` route defaults. |
| `Database\*` | Connection factory, lifecycle (leaked transactions, idle connections), bounded retries. |

## Worker mode pitfalls

- Request-scoped state belongs in a service implementing `Symfony\Contracts\Service\ResetInterface` (register it with `setAutoconfigured(true)`), never in `static` properties. The reset runs after every request.
- There are no server-side sessions, by design: a service stays stateless and identity travels in the token.
- Chunked bodies without `Content-Length` are not caught by `BodySizeLimitMiddleware`; limit them at the edge (Caddy `request_body max_size`).
- Keep the number of worker threads times the number of pods below your PostgreSQL `max_connections` (or put PgBouncer in front).

## Performance

`composer bench` measures what the core itself adds to a request: `Kernel::handle()` with a typical production chain (metrics in APCu, request id, security headers, body limit, authentication), one request at a time, as latency percentiles. It excludes HTTP parsing and the network (Caddy/FrankenPHP) and your own application code. The goal is a p95 below 1 ms; CI enforces a much stricter regression guard (p95 below 250 µs, more than ten times the measured value on the CI runner) and prints the table in the job summary.

Measured on a Windows 11 development machine, PHP 8.4 with opcache and JIT (like a FrankenPHP worker), 30 000 requests per scenario:

| Scenario | p50 µs | p95 µs | p99 µs |
|---|---:|---:|---:|
| `GET /healthz` (public route) | 27.5 | 34.4 | 51.9 |
| `GET /hello/{name}` (authenticated, scope check, JSON response) | 31.3 | 40.3 | 73.7 |
| `GET /unknown` (404 through the chain) | 34.1 | 77.9 | 167.7 |

That is roughly 25 times below the 1 ms goal at p95; on the Linux CI runner the p95 is 19–22 µs. Without opcache (the CLI default) the same run gives a p95 of 50–110 µs. Numbers depend on the machine; this is the overhead of the core, not an end-to-end throughput claim. End-to-end load tests with k6 belong to the demo.
## Roadmap

1. Optional `slimfony/auth-jwt` (verify-only JWT `Authenticator`).
2. **`slimfony/demo`**: a complete reference microservice built on the core (Docker, Caddyfile, Kubernetes manifests, PostgreSQL) with a serious load benchmark (k6, target in the range of 10k requests/s) and published results.
3. Async: transactional outbox and NATS integration, shown in the demo.

## Development

```
composer ci       # phpunit + phpstan (max) + php-cs-fixer
composer bench    # per-request latency of the core (needs APCu with apc.enable_cli=1)
```

The code rules are enforced by the tests; see [GUIDELINES.md](GUIDELINES.md). The code samples in this README are executed by `tests/ReadmeTest.php`, so they cannot rot.

## License

MIT
