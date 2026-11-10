# Authentication

[Back to the README](../README.md)

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

## JWT bearer tokens

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
