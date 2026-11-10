# Metrics and health

[Back to the README](../README.md)

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
