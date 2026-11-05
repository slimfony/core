<?php

declare(strict_types=1);

namespace Slimfony\Core\Log;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Correlation ids of the request being served. Set by RequestIdMiddleware, read by the logger,
 * cleared after every request by the StateResetter.
 */
final class RequestContext implements ResetInterface
{
    public ?string $requestId = null;
    public ?string $traceId = null;

    public function reset(): void
    {
        $this->requestId = null;
        $this->traceId = null;
    }
}
