<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

/**
 * Names of the request attributes the core sets or reads. One place, so a typo cannot silently break correlation.
 */
final class Attributes
{
    public const string REQUEST_ID = 'request_id';
    public const string TRACE_ID = 'trace_id';
    /** set by the router: the service id of the controller */
    public const string CONTROLLER = '_controller';
    /** set by the router: the route name (also the metrics label, never the raw URL) */
    public const string ROUTE = '_route';

    private function __construct() {}
}
