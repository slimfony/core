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
    /** the id of this service's span for the request (16 hex digits); the logger tags every line with it */
    public const string SPAN_ID = 'span_id';
    /** the `traceparent` header to put on every call this service makes while serving the request, so the next service continues the same trace with this request as its parent */
    public const string TRACEPARENT = 'traceparent';
    /** set by the router: the service id of the controller */
    public const string CONTROLLER = '_controller';
    /** set by the router: the route name (also the metrics label, never the raw URL) */
    public const string ROUTE = '_route';
    /** route default: `true` makes the route reachable without authentication */
    public const string PUBLIC = '_public';
    /** route default: list of scopes the caller must all have */
    public const string SCOPES = '_scopes';
    /** route default: `true` makes IdempotencyMiddleware keep the answers of this route by `Idempotency-Key` */
    public const string IDEMPOTENT = '_idempotent';
    /** set by IdempotencyMiddleware while the request runs for the first time: the caller's key, hashed (use it as a unique column to guard the effect) */
    public const string IDEMPOTENCY_KEY = 'idempotency_key';
    /** set by AuthMiddleware: the authenticated Principal */
    public const string PRINCIPAL = '_principal';
    /** set by the Kernel when routing failed (404, 405, bad host); answered after the middleware chain ran */
    public const string ROUTING_ERROR = '_routing_error';

    private function __construct() {}
}
