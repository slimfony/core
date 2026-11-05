<?php

declare(strict_types=1);

use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Tests\Fixtures\Count;
use Slimfony\Core\Tests\Fixtures\Counter;
use Slimfony\Core\Tests\Smoke\Info;
use Slimfony\Core\Tests\Smoke\Ping;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(RequestIdMiddleware::class);
    $c->register(SecurityHeadersMiddleware::class);
    $c->register(Ping::class);
    $c->register(Info::class);
    $c->register(Counter::class)->setAutoconfigured(true);
    $c->register(Count::class)->setAutowired(true);
    $c->setParameter('slimfony.middleware', [RequestIdMiddleware::class, SecurityHeadersMiddleware::class]);
};
