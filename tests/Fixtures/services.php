<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Boom;
use Slimfony\Core\Tests\Fixtures\BrokenReset;
use Slimfony\Core\Tests\Fixtures\Count;
use Slimfony\Core\Tests\Fixtures\Counter;
use Slimfony\Core\Tests\Fixtures\Gone;
use Slimfony\Core\Tests\Fixtures\Greeter;
use Slimfony\Core\Tests\Fixtures\Hello;
use Slimfony\Core\Tests\Fixtures\SeenStatus;
use Slimfony\Core\Tests\Fixtures\UseBroken;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(Greeter::class)->setAutowired(true);
    $c->register(Hello::class)->setAutowired(true);
    $c->register(Boom::class);
    $c->register(Counter::class)->setAutoconfigured(true);
    $c->register(Count::class)->setAutowired(true);
    $c->register(BrokenReset::class)->setAutoconfigured(true);
    $c->register(UseBroken::class)->setAutowired(true);
    $c->register(Gone::class);
    $c->register(SeenStatus::class);
    $c->setParameter('slimfony.middleware', [SeenStatus::class]);
};
