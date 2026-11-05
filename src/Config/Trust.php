<?php

declare(strict_types=1);

namespace Slimfony\Core\Config;

use Slimfony\Core\Exception\ConfigurationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Boot-time trust configuration. Secure default: nothing is trusted, X-Forwarded-* headers are ignored.
 *
 *   TRUSTED_PROXIES  comma separated IPs/CIDRs of your ingress/load balancer (e.g. 10.0.0.0/8), or REMOTE_ADDR
 *   TRUSTED_HOSTS    comma separated exact host names (e.g. api.example.com); other Host headers get a 400
 *
 * apply() is the one place that writes Symfony's static request state. Call it once, before the worker loop.
 *
 * @api
 */
final class Trust
{
    public function __construct(private readonly Env $env) {}

    public function apply(): void
    {
        if (Request::getHttpMethodParameterOverride()) {
            throw new ConfigurationException('HTTP method parameter override must stay disabled.');
        }

        Request::setTrustedProxies(
            self::list($this->env->get('TRUSTED_PROXIES')),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
        Request::setTrustedHosts(array_map(
            static fn(string $host): string => '^' . preg_quote($host) . '$',
            self::list($this->env->get('TRUSTED_HOSTS')),
        ));
    }

    /**
     * @return list<string>
     */
    private static function list(?string $value): array
    {
        return null === $value ? [] : array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => '' !== $item));
    }
}
