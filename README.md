<img src="assets/logo.svg" alt="slimfony logo" width="96" align="right">

# slimfony/core

[![Packagist](https://img.shields.io/packagist/v/slimfony/core.svg)](https://packagist.org/packages/slimfony/core)
[![CI](https://github.com/slimfony/core/actions/workflows/ci.yml/badge.svg)](https://github.com/slimfony/core/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/slimfony/core/graph/badge.svg)](https://codecov.io/gh/slimfony/core)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com/releases/8.1)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A small, strict core for PHP microservices on **FrankenPHP worker mode**. It is built from a few Symfony components, compiled once at build time, and keeps no state between requests.

> **Status:** v0.5.0, pre-1.0. What is covered by SemVer, and what is not, is in [STABILITY.md](STABILITY.md); before 1.0 even that may change in a minor version, and [UPGRADE.md](UPGRADE.md) says what each change asks of your code.

**Contents**

- [Why](#why)
- [When to use it](#when-to-use-it)
- [Demo](#demo)
- [Quickstart](#quickstart)
- [Production](#production)
- [Database](#database)
- [Metrics and health](#metrics-and-health)
- [Authentication](#authentication)
- [Consumers and jobs (without HTTP)](#consumers-and-jobs-without-http)
- [Reliable processing](#reliable-processing)
- [Failure behaviour and restarts](#failure-behaviour-and-restarts)
- [Sizing](#sizing)
- [What is in the box](#what-is-in-the-box)
- [Worker mode pitfalls](#worker-mode-pitfalls)
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
| `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_JWKS_URI` | `index.php` (JWT sample) | Expected `iss`, expected audience and the JWKS endpoint of the identity provider. |
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
- `$registry->gauge(...)->set(212)` records a value that goes up and down (whole numbers in base units: bytes, messages, connections); a gauge shows the last value, not a sum. `Retry` accepts a counter with one `reason` label and counts every retry it makes: `new Retry($connection, retries: $registry->counter('db_retries_total', 'Database retries.', ['reason']))`; a second counter, `exhausted:`, counts the operations that failed even after all attempts (alert on it), and `backoffMicros:` sets the longest pause per attempt.
- A custom `Storage` (for example one shared between processes) implements `add()`, `set()` and `all()`.
## Authentication

An `Authenticator` turns a request into a `Principal` (the caller: subject, scopes, claims) or `null`. `AuthMiddleware` enforces it: put it in the chain (after `RequestIdMiddleware`) and register the authenticator as the `Authenticator` service. From then on **everything is denied by default**: a request without a valid caller gets 401, also on paths that match no route (so the API cannot be mapped by its 404s). Routes opt out or demand scopes through their defaults:

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

### JWT bearer tokens

`JwtAuthenticator` verifies `Authorization: Bearer <JWT>` as a resource server; it never issues tokens. It uses `firebase/php-jwt` (`composer require firebase/php-jwt`) with `ext-openssl` (RS256/384/512, ES256/384) or `ext-sodium` (EdDSA). Without the package it fails at boot with a `ConfigurationException`. Build it in `public/index.php` and hand it over as the synthetic `Authenticator` service:

```php
<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Jwt\JwksKeyProvider;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Config\Env;

$env = Env::fromGlobals();

$authenticator = new JwtAuthenticator(
    new JwtConfiguration($env->string('JWT_ISSUER'), $env->string('JWT_AUDIENCE')),
    new JwksKeyProvider($env->string('JWT_JWKS_URI'), $logger),
    $logger,
);

// Kernel synthetic services: Authenticator::class => $authenticator
```

A token is accepted only if all of this holds:

- Its algorithm is on the allow-list (`RS256`, `ES256`, `EdDSA` by default; shared-secret algorithms and `none` cannot be configured) and equals the algorithm of the key named by its `kid`.
- The signature verifies, `iss` equals the issuer, the audience is among `aud`, `sub` is present, `exp` is present and not past and `nbf` is not in the future (30 seconds of leeway by default).

Anything else is a 401 without a reason. Scopes come from the space separated `scope` claim and the `scp` array.

Keys come from a `KeyProvider`:

- `JwksKeyProvider` reads the identity provider's JWKS endpoint (https only, 3 second timeout, 256 KiB limit). The document is shared between workers in APCu (needs `ext-apcu`); every worker keeps the parsed keys in memory. An unknown `kid` triggers a refetch, at most once per 30 seconds, so random key ids cannot make the service hammer the provider. If the provider is down, the keys already loaded keep working.
- `StaticKeyProvider` holds fixed keys, for example a PEM from a secret file: `new StaticKeyProvider(['key-1' => new Key($pem, 'RS256')])` with `Firebase\JWT\Key`.

A signature check is the most expensive thing a request does (see Performance). A token that verified successfully is remembered by its hash for 30 seconds (`cacheSeconds`), never beyond its own `exp`, so repeated calls with the same token cost a hash and a lookup. A token revoked at the identity provider therefore stays usable for at most that long; `cacheSeconds: 0` verifies every request. Only successes are remembered, so invalid tokens cannot fill the cache.

Any other scheme, for example an identity header set by a trusted gateway, is an `Authenticator` too: return a `Principal`, or `null` when the caller is not authenticated.
## Consumers and jobs (without HTTP)

A queue consumer, an outbox relay or a scheduled job boots the same container as the web workers and reaches its services through `Kernel::get()`; declare the services it needs with `->setPublic(true)`. `ConsumerRunner` is the loop: after every message the request-scoped services are reset, and the loop ends after a number of messages, above a memory limit, on SIGTERM or SIGINT (with `ext-pcntl`), or when the source is empty. A failing reset stops it, because the state is unknown.

```php
<?php

declare(strict_types=1);

use Slimfony\Core\Kernel;
use Slimfony\Core\Worker\ConsumerRunner;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel(__DIR__ . '/../config/services.php', __DIR__ . '/../config/routes.php', cacheDir: __DIR__ . '/../var/cache');
$queue = $kernel->get(MessageQueue::class); // your service, declared public in services.php

new ConsumerRunner($kernel, maxMessages: 10_000)->run(static function () use ($queue): bool {
    $message = $queue->next(); // waits for one message, null when the queue is closed
    if (null === $message) {
        return false;
    }

    $message->handle(); // handle it and acknowledge it: errors are the handler's own business
    $queue->acknowledge($message);

    return true;
});
```

Tag log lines of a message with `RequestContext::start($requestId, $traceId)`, the same way the request id middleware does for a request. The metrics of such a process live in its own memory: give it a `Storage` that is shared with whatever serves `/metrics`.
`ProcessMetricsEndpoint` lets Prometheus scrape such a process, which has no web server: the loop calls `serve()` between messages, it answers at most one waiting `GET /metrics` (access is decided by `MetricsController`, so the token rules are the same) and never blocks.

## Reliable processing

A consumer can die at any line. These rules, in this order, make that harmless; the [demo](https://github.com/slimfony/demo) runs all of them under a chaos script that kills importers and cuts database connections.

1. **Write the message with the data (outbox).** Insert the event into an `outbox` table in the same transaction as the change. A relay publishes the rows (`SELECT ... FOR UPDATE SKIP LOCKED`, so relays never fight over a row) and marks them published. There is never a state with the data but no event.
2. **Claim work with a lease that expires.** A consumer marks a unit of work `processing` with a deadline (`claimed_until`) that is longer than the work should take. A unit whose deadline has passed is free again, so a killed consumer cannot hold work forever.
3. **Make the handler idempotent.** Delivery is at least once: the same message arrives twice after a crash or a timeout. Key the result on the message (a unique constraint, `INSERT ... ON CONFLICT DO NOTHING`, or a status check inside the claim), so the second run changes nothing.
4. **Sweep.** A small periodic job re-publishes outbox rows that stayed unpublished and releases claims whose deadline passed. It is the safety net for everything above; run it in the relay.
5. **Give up visibly.** After the broker's maximum number of deliveries, mark the unit `failed` with the reason instead of retrying forever, and alert on the count (see `exhausted` of `Retry`).

**`Idempotency-Key` for the API.** A client that did not see the answer sends the request again. With `IdempotencyMiddleware` (after `AuthMiddleware`, with a `DbalIdempotencyStore` on your database, its table is `DbalIdempotencyStore::TABLE_SQL`) a route marked `_idempotent: true` answers the repeated `POST` or `PATCH` with the first answer (header `Idempotent-Replayed: true`) instead of doing the work twice. The same key with a different request is `422`, the same request while the first still runs is `409` with `Retry-After`, a `5xx` or an exception is not kept (the client may try again), and keys belong to the caller (the token's subject). The store keeps the answer after the work is done, so if the process dies between the two, the request runs again once the lock has expired: for work that must never happen twice, give the handler a unique column and fill it with the request attribute `Attributes::IDEMPOTENCY_KEY`.

**Deadlocks.** Two transactions that lock the same rows in different order deadlock, and `Retry::transactional()` then re-runs the loser. That is correct but wasteful under load. Prevention is cheaper: lock rows in one agreed order (for example `ORDER BY id` in the statement that locks them, or sort the keys before a batch of upserts). In the demo's import benchmark this alone took 1000 files with 8 importers from 67 s to 13.9 s. If a deadlock does happen, PostgreSQL waits `deadlock_timeout` (1 s by default) before it checks for one; the waiting transaction is stuck for that long. For workloads with short transactions, `deadlock_timeout` of 200 ms to 500 ms is a reasonable trade, but keep `Retry`'s attempts small: it is a net, not a strategy.

## Failure behaviour and restarts

What the core does when something goes wrong, and what is left to the platform. A restart loses what is in memory and what is in flight; the core keeps the *data* consistent, and the caller or the broker repeats what was lost (see [Reliable processing](#reliable-processing)).

| What happens | What the core does | What you set |
|---|---|---|
| A request throws | A generic `500` problem+json without internals, logged with the request id; the worker goes on. | `APP_DEBUG` stays off outside development. |
| A request is slow or hangs | Nothing: the core has no deadline of its own. | Timeouts at the edge (Caddy `timeouts`: `read_header`, `read_body`, `write`) and in the database (`statement_timeout`, the connect timeout of `ConnectionFactory`). |
| Too much load | Nothing: the core does not queue or shed load. | The web server's queue and wait settings, rate limits and `429`/`503` with `Retry-After` at the ingress; `request_body max_size` at the edge. |
| A state reset fails | The worker stops (the state is unknown); the server starts it again. | |
| A worker restarts by itself (`MAX_REQUESTS`, memory limit) | It stops after the response is sent; the other threads keep serving. | `MAX_REQUESTS`, `maxMemoryBytes`. |
| A pod is stopped by a deploy | The server finishes the requests in flight; a consumer finishes its message on SIGTERM. | `maxUnavailable: 0`, a `readinessProbe` that fails first, a `preStop` sleep of 5 to 10 seconds (the load balancer needs a moment to stop sending), `terminationGracePeriodSeconds` above your longest request or message, a `PodDisruptionBudget`. See `k8s/` in the demo. |
| A process is killed (out of memory, crash, node lost) | The request in flight is lost; the database rolls back its open transaction; a consumer's message is not acknowledged and is delivered again. | Idempotent handlers and `Idempotency-Key` (below); the broker's acknowledgement wait. |
| Deadlock or serialization failure | `Retry` runs the whole transaction again, a few times, with jitter. | `maxAttempts`, `backoffMicros`; alert on the `exhausted` counter. |
| The database connection is lost | `Retry` repeats a read; a write only when you declare it `idempotent: true` (a lost COMMIT may or may not have happened). | `idempotent: true` where it is true. |
| A database failover (the old primary is read-only, the new one is starting) | `Retry` repeats the work on a new connection, idempotent or not: the server refused it before anything ran. | Connect through a name that follows the primary (a pooler, a service). |
| A transaction is left open by a request | Rolled back, logged and counted (`db_transactions_leaked_total`). | |
| The identity provider is down | Keys already loaded keep working; a worker that starts with none waits briefly for the first document, then refuses with `401` (closed, not open). | `warmUp()` at boot; `cacheSeconds` for how long a token that was verified is trusted. |
| The process restarts | Counters in APCu start again (Prometheus copes with a counter that resets); the verified-token cache and the keys are loaded again; the compiled container is read from disk. | |

Not in the core, on purpose: durable retries of failed work (an outbox, a broker, a sweeper: see the next chapter), circuit breakers and retries of outgoing HTTP calls, load shedding.

## Sizing

- **Workers.** A worker thread waits for the database and other services most of its time, so a worker count equal to the cores leaves the CPU idle. Start with two to four times the cores (`num_threads` in FrankenPHP's `frankenphp` block, `max_threads` for bursts) and raise it while throughput grows; stop when the database, not PHP, is the limit. In the demo, 16 workers served about 12,400 authenticated reads per second where 4 served about 6,500. Remember the other side of the pool: workers per pod times pods must stay below the database's (or PgBouncer's) connection limit.
- **Garbage collection.** PHP collects reference cycles by itself when enough have piled up, and the request that happens to be at that point pays for it, which shows in the highest percentiles. `new WorkerRunner(..., gc: new GarbageCollection(deferred: true))` switches the collector off while a request is handled and runs it between requests instead, so the cost stays but leaves every response time. Pass counters (`runs:`, `collected:` of `GarbageCollection`) to see how often it runs and what it frees. Whether it matters depends on your code: the collector starts when 10,000 possible cycles have piled up, and code that builds none never reaches that. The [demo](https://github.com/slimfony/demo) is such a service: under 8,000 authenticated requests per second (about 160,000 requests) the collector did not run once, with or without `deferGc` ([the measurement](https://github.com/slimfony/demo/tree/main/benchmarks/results/2026-11-10-gc-probe)). So `deferGc` is insurance for services that do build cycles (object graphs with parent links, closures that capture `$this` and are stored), and the counters tell you whether you are one. `MAX_REQUESTS` stays as the guard against anything that leaks.
- **No worker script.** In FrankenPHP's classic mode `frankenphp_handle_request()` exists but throws; `WorkerRunner` notices and serves the single request, so the same `index.php` runs in both modes (slowly in the classic one: the demo measures worker mode more than ten times faster).

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

## Worker mode pitfalls

- Request-scoped state belongs in a service implementing `Symfony\Contracts\Service\ResetInterface` (register it with `setAutoconfigured(true)`), never in `static` properties. The reset runs after every request.
- The core keeps no server-side sessions: a service stays stateless and identity travels in the token.
- `BodySizeLimitMiddleware` also limits a body without a declared length (it reads at most the limit plus one byte); still set `request_body max_size` at the edge, so an oversized upload is stopped before PHP.
- To continue a trace, put the request attribute `Attributes::TRACEPARENT` into the `traceparent` header of the calls the service makes; the log lines carry `trace_id` and `span_id`, and an OpenTelemetry SDK can use the same ids to export spans.
- Keep the number of worker threads times the number of pods below your PostgreSQL `max_connections` (or put PgBouncer in front).

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
