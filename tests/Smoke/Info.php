<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Smoke;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Memory of the worker that served this request, for the leak check in smoke.sh. */
final class Info
{
    public function __invoke(Request $request): Response
    {
        return new Response((string) memory_get_usage());
    }
}
