<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Secure API defaults. A controller may opt into caching with an explicit public/max-age Cache-Control.
 * HSTS is left to the ingress/Caddy.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, \Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        if (!$headers->hasCacheControlDirective('public') && !$headers->hasCacheControlDirective('max-age')) {
            $headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
