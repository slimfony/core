# slimfony/core

A small, strict core for PHP microservices running on **FrankenPHP worker mode**. It is built from a handful of Symfony components, compiled once at boot, and leaks no state between requests.

> **Status:** pre-alpha. The API may still change.

## Why

- **Fast:** nothing is computed per request that can be done at boot (container, router, middleware chain).
- **Small:** no YAML, no event dispatcher, no polyfills, PHP 8.4+. Runtime dependencies are limited to `http-foundation`, `routing`, `dependency-injection`, `doctrine/dbal` and `psr/log`.
- **Secure by default:** no leaked errors, validated request ids, security headers, body size limit.
- **Worker-safe:** services are reset after every request, and the worker stops if a reset fails.

Rules and the scope of the core are in [GUIDELINES.md](GUIDELINES.md).

## Quickstart

Install into your own service: `composer require slimfony/core` (not on Packagist yet). It is the base of a service, not a library for arbitrary apps: PHP 8.4+, newest Symfony/DBAL only, and the service commits its own `composer.lock`.

`config/services.php`

```php
use Slimfony\Core\Http\{RequestIdMiddleware, SecurityHeadersMiddleware};
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(RequestIdMiddleware::class);
    $c->register(SecurityHeadersMiddleware::class);
    $c->register(App\Hello::class)->setAutowired(true);

    $c->setParameter('slimfony.middleware', [RequestIdMiddleware::class, SecurityHeadersMiddleware::class]);
};
```

`config/routes.php`

```php
use Symfony\Component\Routing\{Route, RouteCollection};

return static function (RouteCollection $r): void {
    $r->add('hello', new Route('/hello/{name}', ['_controller' => App\Hello::class], methods: ['GET']));
};
```

A controller is an invokable service:

```php
final class Hello
{
    public function __invoke(Request $request): Response
    {
        return new Response('hello ' . $request->attributes->getString('name'));
    }
}
```

`public/index.php`

```php
require __DIR__ . '/../vendor/autoload.php';

$kernel = new Slimfony\Core\Kernel(__DIR__ . '/../config/services.php', __DIR__ . '/../config/routes.php');

(new Slimfony\Core\Worker\WorkerRunner($kernel, maxRequests: (int) getenv('MAX_REQUESTS')))->run();
```

Without FrankenPHP the runner serves a single request, so the same file works as a classic front controller.

## What is in the box

| Component | What it does |
|---|---|
| `Kernel` | Boots once, routes, runs controllers, turns errors into `application/problem+json` (404, 405 with `Allow`, 413, 500). |
| `Http\*Middleware` | `RequestId` (request id + trace id, forged values replaced), `SecurityHeaders`, `BodySizeLimit`. |
| `Worker\WorkerRunner` | The worker loop: max requests, memory limit, optional GC, fail-fast on reset errors. |
| `Worker\StateResetter` | Resets every service implementing `ResetInterface` that was used during the request. |

## Worker mode pitfalls

- Request-scoped state belongs in a service implementing `Symfony\Contracts\Service\ResetInterface` (register it with `setAutoconfigured(true)`), never in `static` properties.
- There are no server-side sessions by design. Services stay stateless; identity travels in the token.
- Chunked bodies without `Content-Length` are not caught by `BodySizeLimit`; limit them at the edge (Caddy `request_body max_size`).

## Roadmap

1. Build-time warmup (`bin/slimfony warmup`: dumped container and router).
2. Prometheus metrics, `/healthz` and `/readyz`, JSON logger, env/`*_FILE` config.
3. Auth contract, then an optional `slimfony/auth-jwt`.
4. **`slimfony/demo`**: a complete reference microservice built on the core (Docker, Caddyfile, Kubernetes manifests, PostgreSQL) with a serious load benchmark (k6, target in the range of 10k requests/s) and published results.
5. Async: transactional outbox and NATS integration, shown in the demo.

## Development

```
composer ci    # phpunit + phpstan (max) + php-cs-fixer
```

## License

MIT
