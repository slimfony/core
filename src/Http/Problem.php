<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 9457 problem+json responses.
 */
final class Problem
{
    /**
     * @param array<string, string> $headers
     */
    public static function response(
        int $status,
        ?string $requestId = null,
        string $detail = '',
        array $headers = [],
    ): Response {
        $body = ['type' => 'about:blank', 'title' => Response::$statusTexts[$status] ?? 'Error', 'status' => $status];
        if ('' !== $detail) {
            $body['detail'] = $detail;
        }
        if (null !== $requestId) {
            $body['request_id'] = $requestId;
        }

        return new Response(
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
        );
    }
}
