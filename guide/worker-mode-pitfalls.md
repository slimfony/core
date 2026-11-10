# Worker mode pitfalls

[Back to the README](../README.md)

- Request-scoped state belongs in a service implementing `Symfony\Contracts\Service\ResetInterface` (register it with `setAutoconfigured(true)`), never in `static` properties. The reset runs after every request.
- The core keeps no server-side sessions: a service stays stateless and identity travels in the token.
- `BodySizeLimitMiddleware` also limits a body without a declared length (it reads at most the limit plus one byte); still set `request_body max_size` at the edge, so an oversized upload is stopped before PHP.
- To continue a trace, put the request attribute `Attributes::TRACEPARENT` into the `traceparent` header of the calls the service makes; the log lines carry `trace_id` and `span_id`, and an OpenTelemetry SDK can use the same ids to export spans.
- Keep the number of worker threads times the number of pods below your PostgreSQL `max_connections` (or put PgBouncer in front).

