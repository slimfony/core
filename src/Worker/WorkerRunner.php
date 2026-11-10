<?php

declare(strict_types=1);

namespace Slimfony\Core\Worker;

use Psr\Log\LoggerInterface;
use Slimfony\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * FrankenPHP worker loop. Exits (FrankenPHP then restarts the script) when the server asks for it,
 * after $maxRequests requests, above $maxMemoryBytes, or if a state reset fails (state is unknown: fail fast).
 * Without FrankenPHP, and in FrankenPHP's classic mode (no worker script), it serves exactly one request, so it works
 * as a plain front controller too.
 *
 * Signals are not handled here: FrankenPHP/Caddy own SIGTERM and make the handler function return false.
 *
 * Garbage collection (`$gc`, see GarbageCollection): PHP runs its cycle collector by itself, when enough possible cycles have
 * piled up, and a request that happens to be the one at that moment pays for it (a latency spike in the high percentiles). With
 * `deferred: true` the collector is switched off while a request is handled and runs between requests instead, after the
 * response is sent. The cost is the same, it is just no longer in anybody's response time. Counters show how often it runs and
 * what it frees.
 *
 * @api
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
     * @param int                                     $maxMemoryBytes  0 = off
     * @param GarbageCollection                       $gc              what to do about PHP's cycle collector
     */
    public function __construct(
        private readonly Kernel $kernel,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $handleRequest = null,
        ?\Closure $requestFactory = null,
        private int $maxRequests = 0 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('maxRequests must not be negative.'); },
        private int $maxMemoryBytes = 0 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('maxMemoryBytes must not be negative.'); },
        private readonly GarbageCollection $gc = new GarbageCollection(),
    ) {
        $this->handleRequest = $handleRequest ?? self::frankenphpOrOnce();
        $this->requestFactory = $requestFactory ?? Request::createFromGlobals(...);
    }

    /**
     * The handler function of FrankenPHP's worker mode when there is one; otherwise (no FrankenPHP, or its classic mode) a
     * function that serves exactly one request.
     *
     * FrankenPHP defines `frankenphp_handle_request()` in its classic mode too, where calling it throws; so the existence of the
     * function says nothing about the mode, and the exception is the only signal there is.
     *
     * @return \Closure(callable(): void): bool
     */
    private static function frankenphpOrOnce(): \Closure
    {
        $once = static function (callable $handler): bool {
            $handler();

            return false;
        };
        if (!\function_exists('frankenphp_handle_request')) {
            return $once;
        }

        return static function (callable $handler) use ($once): bool {
            try {
                return frankenphp_handle_request($handler);
            } catch (\RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'not in worker mode')) {
                    throw $e;
                }

                return $once($handler); // classic mode: this script run is the one request
            }
        };
    }

    public function run(): void
    {
        ignore_user_abort(true);
        $handler = $this->serve(...);
        $served = 0;
        $collectorWasOn = gc_enabled();
        if ($this->gc->deferred) {
            gc_disable();
        }

        try {
            $this->loop($handler, $served);
        } finally {
            if ($this->gc->deferred && $collectorWasOn) {
                gc_enable();
            }
        }
    }

    /**
     * @param \Closure(): void $handler
     */
    private function loop(\Closure $handler, int &$served): void
    {
        do {
            $keepRunning = ($this->handleRequest)($handler);
            ++$served;

            try {
                $this->kernel->reset(); // after the response is complete, so it never delays the client
            } catch (\Throwable $e) {
                $this->logger?->critical('State reset failed, stopping worker', ['exception' => $e]);

                return;
            }

            $this->collectGarbageIfDue($served);
        } while ($keepRunning && !$this->mustStop($served));
    }

    private int $seenRuns = 0;
    private int $seenCollected = 0;

    private function collectGarbageIfDue(int $served): void
    {
        $due = ($this->gc->every > 0 && 0 === $served % $this->gc->every)
            || ($this->gc->deferred && gc_status()['roots'] >= $this->gc->rootsThreshold);
        if ($due) {
            gc_collect_cycles();
        }

        if (null !== $this->gc->runs || null !== $this->gc->collected) { // report what the collector did since the last look, whoever started it
            $status = gc_status();
            if ($status['runs'] > $this->seenRuns) {
                $this->gc->runs?->inc(by: $status['runs'] - $this->seenRuns);
            }
            if ($status['collected'] > $this->seenCollected) {
                $this->gc->collected?->inc(by: $status['collected'] - $this->seenCollected);
            }
            $this->seenRuns = $status['runs'];
            $this->seenCollected = $status['collected'];
        }
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
