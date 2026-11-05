<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Fixtures;

use Slimfony\Core\Http\MiddlewareInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ThrowingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, \Closure $next): Response
    {
        throw new \RuntimeException('secret middleware failure');
    }
}
