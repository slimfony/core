<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A careless controller that echoes instead of returning everything in the Response. */
final class Chatty
{
    public function __invoke(Request $request): Response
    {
        echo 'stray output';

        return new Response('ok');
    }
}
