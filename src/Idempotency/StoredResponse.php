<?php

declare(strict_types=1);

namespace Slimfony\Core\Idempotency;

/**
 * The part of a response that is kept to answer a repeated request.
 *
 * @api
 */
final readonly class StoredResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public string $contentType,
    ) {}
}
