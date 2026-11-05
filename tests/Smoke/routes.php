<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Count;
use Slimfony\Core\Tests\Smoke\Info;
use Slimfony\Core\Tests\Smoke\Logs;
use Slimfony\Core\Tests\Smoke\Ping;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('ping', new Route('/ping', ['_controller' => Ping::class]));
    $r->add('info', new Route('/info', ['_controller' => Info::class]));
    $r->add('log', new Route('/log', ['_controller' => Logs::class]));
    $r->add('count', new Route('/count', ['_controller' => Count::class]));
};
