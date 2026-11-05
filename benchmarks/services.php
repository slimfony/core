<?php

declare(strict_types=1);

use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\AuthMiddleware;
use Slimfony\Core\Benchmarks\Hello;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Http\BodySizeLimitMiddleware;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Observability\MetricsMiddleware;
use Slimfony\Core\Observability\MetricsRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// The production chain of a typical service: metrics, request id, security headers, body limit, authentication.
return static function (ContainerBuilder $c): void {
    $c->register(MetricsRegistry::class)->setSynthetic(true)->setPublic(true);
    $c->register(Authenticator::class)->setSynthetic(true)->setPublic(true);

    $c->register(MetricsMiddleware::class)->setAutowired(true);
    $c->register(RequestIdMiddleware::class);
    $c->register(SecurityHeadersMiddleware::class);
    $c->register(BodySizeLimitMiddleware::class);
    $c->register(AuthMiddleware::class)->setAutowired(true);
    $c->setParameter('slimfony.middleware', [
        MetricsMiddleware::class,
        RequestIdMiddleware::class,
        SecurityHeadersMiddleware::class,
        BodySizeLimitMiddleware::class,
        AuthMiddleware::class,
    ]);

    $c->register(LivenessController::class);
    $c->register(Hello::class);
};
