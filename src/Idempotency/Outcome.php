<?php

declare(strict_types=1);

namespace Slimfony\Core\Idempotency;

/**
 * What an idempotency store says about a key when a request starts.
 *
 * @api
 */
enum Outcome
{
    /** Never seen (or expired): the request runs. */
    case Fresh;
    /** The same request is being served right now, by this or another worker. */
    case InProgress;
    /** The key was used before with a different request. */
    case Mismatch;
    /** The same request was served before: its response is stored. */
    case Done;
}
