<?php

declare(strict_types=1);

namespace Slimfony\Core\Log;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Correlation ids of the request being served. Started by RequestIdMiddleware, read by the logger,
 * cleared after every request by the StateResetter.
 *
 * @api
 */
final class RequestContext implements ResetInterface
{
    public private(set) ?string $requestId = null;
    public private(set) ?string $traceId = null;
    public private(set) ?string $spanId = null;

    public function start(string $requestId, string $traceId, ?string $spanId = null): void
    {
        $this->requestId = $requestId;
        $this->traceId = $traceId;
        $this->spanId = $spanId;
    }

    #[\Override]
    public function reset(): void
    {
        $this->requestId = null;
        $this->traceId = null;
        $this->spanId = null;
    }
}
