<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Boom;
use Slimfony\Core\Tests\Fixtures\Gone;
use Slimfony\Core\Tests\Fixtures\Hello;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('hello', (new Route('/hello/{name}', ['_controller' => Hello::class]))->setMethods(['GET']));
    $r->add('boom', new Route('/boom', ['_controller' => Boom::class]));
    $r->add('gone', new Route('/gone', ['_controller' => Gone::class]));
};
