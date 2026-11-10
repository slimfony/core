# Production

[Back to the README](../README.md)

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

