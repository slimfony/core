<?php

declare(strict_types=1);

namespace Slimfony\Core\Health;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /readyz: 200 when every check passes, 503 otherwise. The response names the failed checks but never their
 * errors (those go to the log). The result is cached for a couple of seconds so probes cannot load the database.
 * The cache is a deliberate time-based exception to "no state between requests": it holds no request data.
 *
 * @api
 */
final class ReadinessController
{
    /** @var \Closure(): float */
    private readonly \Closure $now;
    /** @var list<string>|null failed check names of the last run */
    private ?array $lastFailed = null;
    private float $lastRunAt = 0.0;

    /**
     * @param list<ReadinessCheck>     $checks
     * @param (\Closure(): float)|null $now    seconds; defaults to microtime(true)
     */
    public function __construct(
        private readonly array $checks,
        private readonly ?LoggerInterface $logger = null,
        private int $cacheSeconds = 2 { set => $value >= 0 ? $value : throw new \InvalidArgumentException('cacheSeconds must not be negative.'); },
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): float => microtime(true);
    }

    public function __invoke(Request $request): Response
    {
        $now = ($this->now)();
        if (null === $this->lastFailed || $now - $this->lastRunAt >= $this->cacheSeconds) {
            $this->lastFailed = $this->run();
            $this->lastRunAt = $now;
        }

        $body = [] === $this->lastFailed
            ? ['status' => 'ok']
            : ['status' => 'unavailable', 'failed' => $this->lastFailed];

        return new Response(json_encode($body, \JSON_THROW_ON_ERROR), [] === $this->lastFailed ? 200 : 503, ['Content-Type' => 'application/json']);
    }

    /**
     * @return list<string>
     */
    private function run(): array
    {
        $failed = [];
        foreach ($this->checks as $check) {
            try {
                $check->check();
            } catch (\Throwable $e) {
                $failed[] = $check->name();
                $this->logger?->warning('Readiness check failed: {check}', ['check' => $check->name(), 'exception' => $e]);
            }
        }

        return $failed;
    }
}
