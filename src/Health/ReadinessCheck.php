<?php

declare(strict_types=1);

namespace Slimfony\Core\Health;

/**
 * One dependency the service needs before it may receive traffic (database, broker, ...).
 *
 * @api
 */
interface ReadinessCheck
{
    /** Short stable name, shown in the readiness response and the log (e.g. "database"). */
    public function name(): string;

    /**
     * @throws \Throwable when the dependency is not usable
     */
    public function check(): void;
}
