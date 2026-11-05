<?php

declare(strict_types=1);

namespace Slimfony\Core\Http;

/**
 * Names of the request attributes the core sets or reads. One place, so a typo cannot silently break correlation.
 *
 * @api
 */
final class Attributes
{
    public const string REQUEST_ID = 'request_id';
    public const string TRACE_ID = 'trace_id';
    /** set by the router: the service id of the controller */
    public const string CONTROLLER = '_controller';
    /** set by the router: the route name (also the metrics label, never the raw URL) */
    public const string ROUTE = '_route';
    /** route default: `true` makes the route reachable without authentication */
    public const string PUBLIC = '_public';
    /** route default: list of scopes the caller must all have */
    public const string SCOPES = '_scopes';
    /** set by AuthMiddleware: the authenticated Principal */
    public const string PRINCIPAL = '_principal';
    /** set by the Kernel when routing failed (404, 405, bad host); answered after the middleware chain ran */
    public const string ROUTING_ERROR = '_routing_error';

    private function __construct() {}
}
