<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Greeter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(Greeter::class);
    $c->setParameter('slimfony.middleware', [Greeter::class]);
};
