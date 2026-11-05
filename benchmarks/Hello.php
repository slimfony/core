<?php

declare(strict_types=1);

namespace Slimfony\Core\Benchmarks;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A typical small JSON endpoint with a route parameter. */
final class Hello
{
    public function __invoke(Request $request): Response
    {
        return new JsonResponse(['hello' => $request->attributes->getString('name')]);
    }
}
