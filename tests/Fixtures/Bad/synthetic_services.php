<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Greeter;
use Slimfony\Core\Tests\Fixtures\Hello;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// Greeter is created outside (e.g. needs config read at boot) and handed to the Kernel
return static function (ContainerBuilder $c): void {
    $c->register(Greeter::class)->setSynthetic(true)->setPublic(true);
    $c->register(Hello::class)->setAutowired(true);
};
