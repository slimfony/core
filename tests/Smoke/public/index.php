<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\JsonStdoutLogger;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Worker\WorkerRunner;

require __DIR__ . '/../../../vendor/autoload.php';

$env = Env::fromGlobals();
$context = new RequestContext();
$logger = new JsonStdoutLogger($env->string('LOG_LEVEL', 'info'), $context);

$kernel = new Kernel(
    __DIR__ . '/../services.php',
    __DIR__ . '/../routes.php',
    debug: $env->bool('APP_DEBUG'),
    logger: $logger,
    cacheDir: __DIR__ . '/../cache',
    synthetic: [RequestContext::class => $context, LoggerInterface::class => $logger],
);

(new WorkerRunner($kernel, $logger, maxRequests: $env->int('MAX_REQUESTS', 0)))->run();
