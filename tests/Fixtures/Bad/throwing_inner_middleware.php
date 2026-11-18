<?php

declare(strict_types=1);

use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Tests\Fixtures\ThrowingMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(RequestIdMiddleware::class)->setAutowired(true);
    $c->register(SecurityHeadersMiddleware::class);
    $c->register(ThrowingMiddleware::class);
    $c->setParameter('slimfony.middleware', [RequestIdMiddleware::class, SecurityHeadersMiddleware::class, ThrowingMiddleware::class]);
};
