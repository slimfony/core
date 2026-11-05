<?php

declare(strict_types=1);

use Slimfony\Core\Benchmarks\Hello;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Http\Attributes;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('healthz', new Route('/healthz', ['_controller' => LivenessController::class, Attributes::PUBLIC => true], methods: ['GET']));
    $r->add('hello', new Route('/hello/{name}', ['_controller' => Hello::class, Attributes::SCOPES => ['bench:read']], methods: ['GET']));
};
