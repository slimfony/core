<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Hello;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('hello', new Route('/hello/{name}', ['_controller' => Hello::class]));
};
