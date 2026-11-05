<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\ThrowingMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(ThrowingMiddleware::class);
    $c->setParameter('slimfony.middleware', [ThrowingMiddleware::class]);
};
