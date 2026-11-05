<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 9457 problem+json response. $detail is client-facing: never put internals in it.
 */
final class ProblemResponse extends Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, ?string $requestId = null, string $detail = '', array $headers = [])
    {
        $body = ['type' => 'about:blank', 'title' => self::$statusTexts[$status] ?? 'Error', 'status' => $status];
        if ('' !== $detail) {
            $body['detail'] = $detail;
        }
        if (null !== $requestId) {
            $body['request_id'] = $requestId;
        }

        parent::__construct(
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
        );
    }
}
