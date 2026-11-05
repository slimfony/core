<?php

declare(strict_types=1);

namespace Slimfony\Core\Worker;

use Psr\Log\LoggerInterface;
use Slimfony\Core\Kernel;

/**
 * The loop of a long-running process that is not an HTTP worker: a queue consumer, an outbox relay, a scheduled job.
 * It does for messages what WorkerRunner does for requests: after every message the request-scoped services are
 * reset (a failing reset stops the loop: the state is unknown), and the loop ends after a number of messages or above a
 * memory limit, so the orchestrator can restart a fresh process.
 *
 * The caller owns the message source. $handleNext waits for ONE message and handles it, including acknowledging it and
 * its own error handling; an exception it throws ends the loop and the process.
 *
 * SIGTERM and SIGINT stop the loop after the message being handled (when the pcntl extension is available).
 *
 * @api
 */
final class ConsumerRunner
{
    private bool $stopRequested = false;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly ?LoggerInterface $logger = null,
        private int $maxMessages = 0 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('maxMessages must not be negative.'); },
        private int $maxMemoryBytes = 0 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('maxMemoryBytes must not be negative.'); },
    ) {}

    /**
     * Ends the loop after the message being handled. Safe to call from the handler or from a signal handler.
     */
    public function stop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * @param \Closure(): bool $handleNext waits for and handles one message; returns false when there is nothing more to consume
     */
    public function run(\Closure $handleNext): void
    {
        $this->stopRequested = false;
        $this->stopOnSignals();

        $handled = 0;
        do {
            $more = $handleNext();
            ++$handled;

            try {
                $this->kernel->reset();
            } catch (\Throwable $e) {
                $this->logger?->critical('State reset failed, stopping consumer', ['exception' => $e]);

                return;
            }
        } while ($more && !$this->stopRequested && !$this->mustStop($handled));
    }

    private function mustStop(int $handled): bool
    {
        return ($this->maxMessages > 0 && $handled >= $this->maxMessages)
            || ($this->maxMemoryBytes > 0 && memory_get_usage() > $this->maxMemoryBytes);
    }

    private function stopOnSignals(): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        foreach (['SIGTERM', 'SIGINT'] as $name) { // by name: the constants exist only where pcntl does
            $signal = \defined($name) ? \constant($name) : null;
            if (\is_int($signal)) {
                pcntl_signal($signal, $this->stop(...));
            }
        }
    }
}
