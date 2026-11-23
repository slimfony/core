<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A controller that starts an output buffer and never closes it. */
final class Leaky
{
    public function __invoke(Request $request): Response
    {
        ob_start();
        echo 'in a buffer nobody closes';

        return new Response('ok');
    }
}
