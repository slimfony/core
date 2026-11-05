<?php

declare(strict_types=1);

namespace Slimfony\Core\Exception;

/**
 * Thrown by application code to produce a specific HTTP error response.
 * $detail is client-facing: never put internals in it.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public private(set) int $status { set => $value >= 400 && $value <= 599 ? $value : throw new \InvalidArgumentException('An HTTP error status must be 4xx or 5xx.'); },
        public readonly string $detail = '',
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, 0, $previous);
    }
}
