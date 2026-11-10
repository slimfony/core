# Database

[Back to the README](../README.md)

PostgreSQL through DBAL, safe across requests in a long-lived worker. The pieces are plain objects, wire them in `index.php` and hand them to the container as synthetic services:

```php
<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionFactory;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;

$env = Env::fromGlobals();

$connection = ConnectionFactory::create($env->string('DATABASE_URL'), transactionPooling: $env->bool('PGBOUNCER'));
$lifecycle = new ConnectionLifecycle($connection, idleSeconds: 30); // register it as a synthetic service tagged with ResetPass::TAG
$retry = new Retry($connection);

// transient failures: the whole transaction is re-run on deadlock; a lost connection only with idempotent: true
$retry->transactional(static function (Connection $db): void {
    $db->executeStatement('UPDATE counters SET n = n + 1 WHERE id = ?', [1]);
}, idempotent: false);
```

`ConnectionLifecycle` rolls back (and logs) a transaction a request left open, and recycles a connection that has been idle too long. Add `ConnectionLifecycleMiddleware` to the chain to enable the idle check. All SQL uses bound parameters.

