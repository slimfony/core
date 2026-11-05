<?php

declare(strict_types=1);

namespace Slimfony\Core\Worker;

use Psr\Log\LoggerInterface;
use Slimfony\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * FrankenPHP worker loop. Exits (FrankenPHP then restarts the script) when the server asks for it,
 * after $maxRequests requests, above $maxMemoryBytes, or if a state reset fails (state is unknown: fail fast).
 * Without FrankenPHP it serves exactly one request, so it works as a plain front controller too.
 *
 * Signals are not handled here: FrankenPHP/Caddy own SIGTERM and make the handler function return false.
 */
final class WorkerRunner
{
    /** @var \Closure(callable(): void): bool */
    private readonly \Closure $handleRequest;
    /** @var \Closure(): Request */
    private readonly \Closure $requestFactory;

    /**
     * @param (\Closure(callable(): void): bool)|null $handleRequest   defaults to frankenphp_handle_request
     * @param (\Closure(): Request)|null              $requestFactory  defaults to Request::createFromGlobals
     * @param int                                     $maxRequests     0 = unlimited
     * @param int                                     $gcEvery         gc_collect_cycles() every N requests, 0 = rely on PHP
     * @param int                                     $maxMemoryBytes  0 = off
     */
    public function __construct(
        private readonly Kernel $kernel,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $handleRequest = null,
        ?\Closure $requestFactory = null,
        private readonly int $maxRequests = 0,
        private readonly int $gcEvery = 0,
        private readonly int $maxMemoryBytes = 0,
    ) {
        $this->handleRequest = $handleRequest ?? (\function_exists('frankenphp_handle_request')
            ? \Closure::fromCallable('frankenphp_handle_request')
            : static function (callable $handler): bool {
                $handler();

                return false;
            });
        $this->requestFactory = $requestFactory ?? Request::createFromGlobals(...);
    }

    public function run(): void
    {
        ignore_user_abort(true);
        $handler = $this->serve(...);
        $served = 0;

        do {
            $keepRunning = ($this->handleRequest)($handler);
            ++$served;

            try {
                $this->kernel->reset(); // after the response is complete, so it never delays the client
            } catch (\Throwable $e) {
                $this->logger?->critical('State reset failed, stopping worker', ['exception' => $e]);

                return;
            }

            if ($this->gcEvery > 0 && 0 === $served % $this->gcEvery) {
                gc_collect_cycles();
            }
        } while ($keepRunning && !$this->mustStop($served));
    }

    private function serve(): void
    {
        ob_start();
        try {
            $response = $this->kernel->handle(($this->requestFactory)());
        } finally {
            $stray = (string) ob_get_clean();
        }
        if ('' !== $stray) {
            $this->logger?->warning('Controller wrote directly to the output; discarded', ['bytes' => \strlen($stray)]);
        }

        $response->send(false); // false: no finish_request/flush games, FrankenPHP completes the request itself
    }

    private function mustStop(int $served): bool
    {
        return ($this->maxRequests > 0 && $served >= $this->maxRequests)
            || ($this->maxMemoryBytes > 0 && memory_get_usage() > $this->maxMemoryBytes);
    }
}
