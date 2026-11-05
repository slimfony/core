<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->setParameter('app.secret', '%env(SLIMFONY_TEST_SECRET)%');
};
