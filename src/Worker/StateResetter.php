<?php

declare(strict_types=1);

namespace Slimfony\Core\Worker;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Resets every service that was actually used during the request (see ResetPass).
 */
final class StateResetter
{
    /**
     * @param iterable<ResetInterface> $services only already-initialized services
     */
    public function __construct(private readonly iterable $services) {}

    public function reset(): void
    {
        foreach ($this->services as $service) {
            $service->reset();
        }
    }
}
