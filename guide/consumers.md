# Consumers and jobs (without HTTP)

[Back to the README](../README.md)

A queue consumer, an outbox relay or a scheduled job boots the same container as the web workers and reaches its services through `Kernel::get()`; declare the services it needs with `->setPublic(true)`. `ConsumerRunner` is the loop: after every message the request-scoped services are reset, and the loop ends after a number of messages, above a memory limit, on SIGTERM or SIGINT (with `ext-pcntl`), or when the source is empty. A failing reset stops it, because the state is unknown.

```php
<?php

declare(strict_types=1);

use Slimfony\Core\Kernel;
use Slimfony\Core\Worker\ConsumerRunner;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel(__DIR__ . '/../config/services.php', __DIR__ . '/../config/routes.php', cacheDir: __DIR__ . '/../var/cache');
$queue = $kernel->get(MessageQueue::class); // your service, declared public in services.php

new ConsumerRunner($kernel, maxMessages: 10_000)->run(static function () use ($queue): bool {
    $message = $queue->next(); // waits for one message, null when the queue is closed
    if (null === $message) {
        return false;
    }

    $message->handle(); // handle it and acknowledge it: errors are the handler's own business
    $queue->acknowledge($message);

    return true;
});
```

Tag log lines of a message with `RequestContext::start($requestId, $traceId)`, the same way the request id middleware does for a request. The metrics of such a process live in its own memory: give it a `Storage` that is shared with whatever serves `/metrics`.
`ProcessMetricsEndpoint` lets Prometheus scrape such a process, which has no web server: the loop calls `serve()` between messages, it answers at most one waiting `GET /metrics` (access is decided by `MetricsController`, so the token rules are the same) and never blocks.

