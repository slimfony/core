<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
interface MiddlewareInterface
{
    /**
     * @param \Closure(Request): Response $next
     */
    public function process(Request $request, \Closure $next): Response;
}
