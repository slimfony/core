<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A controller that answers 204 and, by mistake, with a body. */
final class NoContent
{
    public function __invoke(Request $request): Response
    {
        return new Response('a body that must not be sent', 204);
    }
}
