<?php

declare(strict_types=1);

use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Tests\Fixtures\Open;
use Slimfony\Core\Tests\Fixtures\Whoami;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('open', new Route('/open', ['_controller' => Open::class, Attributes::PUBLIC => true], methods: ['GET']));
    $r->add('me', new Route('/me', ['_controller' => Whoami::class]));
    $r->add('orders', new Route('/orders', ['_controller' => Whoami::class, Attributes::SCOPES => ['orders:read']]));
};
