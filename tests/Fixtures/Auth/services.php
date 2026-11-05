<?php

declare(strict_types=1);

use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\AuthMiddleware;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Tests\Fixtures\Open;
use Slimfony\Core\Tests\Fixtures\Whoami;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(Authenticator::class)->setSynthetic(true)->setPublic(true);
    $c->register(RequestIdMiddleware::class);
    $c->register(AuthMiddleware::class)->setAutowired(true);
    $c->setParameter('slimfony.middleware', [RequestIdMiddleware::class, AuthMiddleware::class]);

    $c->register(Open::class);
    $c->register(Whoami::class);
};
