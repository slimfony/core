<?php

declare(strict_types=1);

use Slimfony\Core\Tests\Fixtures\Boom;
use Slimfony\Core\Tests\Fixtures\Chatty;
use Slimfony\Core\Tests\Fixtures\NotAResponse;
use Slimfony\Core\Tests\Fixtures\Count;
use Slimfony\Core\Tests\Fixtures\UseBroken;
use Slimfony\Core\Tests\Fixtures\Gone;
use Slimfony\Core\Tests\Fixtures\Hello;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('hello', new Route('/hello/{name}', ['_controller' => Hello::class])->setMethods(['GET']));
    $r->add('count', new Route('/count', ['_controller' => Count::class]));
    $r->add('broken', new Route('/broken', ['_controller' => UseBroken::class]));
    $r->add('chatty', new Route('/chatty', ['_controller' => Chatty::class]));
    $r->add('notresponse', new Route('/notresponse', ['_controller' => NotAResponse::class]));
    $r->add('boom', new Route('/boom', ['_controller' => Boom::class]));
    $r->add('gone', new Route('/gone', ['_controller' => Gone::class]));
};
