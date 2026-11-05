<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Boom;
use Slimfony\Core\Tests\Fixtures\Gone;
use Slimfony\Core\Tests\Fixtures\Greeter;
use Slimfony\Core\Tests\Fixtures\Hello;
use Slimfony\Core\Tests\Fixtures\SeenStatus;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(Greeter::class)->setAutowired(true);
    $c->register(Hello::class)->setAutowired(true);
    $c->register(Boom::class);
    $c->register(Gone::class);
    $c->register(SeenStatus::class);
    $c->setParameter('slimfony.middleware', [SeenStatus::class]);
};
