<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Slimfony\Core\Kernel;
use Slimfony\Core\Worker\WorkerRunner;

require __DIR__ . '/../../../vendor/autoload.php';

// Smoke app only: real services get the JSON logger. Anything at error level or above fails the smoke test.
$logger = new class extends AbstractLogger {
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        error_log('SMOKE-LOG ' . (is_string($level) ? $level : 'unknown') . ' ' . $message);
    }
};

$kernel = new Kernel(__DIR__ . '/../services.php', __DIR__ . '/../routes.php', false, $logger);

(new WorkerRunner($kernel, $logger, maxRequests: (int) getenv('MAX_REQUESTS')))->run();
