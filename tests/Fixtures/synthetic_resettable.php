<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Support\ResetCounter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    // no tag: implementing ResetInterface is enough
    $c->register(ResetCounter::class)->setSynthetic(true)->setPublic(true);
    $c->setParameter('slimfony.middleware', []);
};
