<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Smoke;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Ping
{
    public function __invoke(Request $request): Response
    {
        return new Response('pong');
    }
}
