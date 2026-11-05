<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TestKernel;
use Slimfony\Core\Worker\WorkerRunner;
use Symfony\Component\HttpFoundation\Request;

final class WorkerRunnerTest extends TestCase
{
    /**
     * Runs the worker loop; the fake server delivers $deliveries requests to $path, then asks the worker to stop.
     *
     * @return array{output: string, handled: int}
     */
    private function runWorker(string $path, int $deliveries, int $maxRequests = 0, ?RecordingLogger $logger = null): array
    {
        $handled = 0;
        $server = static function (callable $handler) use (&$handled, $deliveries): bool {
            $handler();

            return ++$handled < $deliveries;
        };

        ob_start();
        new WorkerRunner(TestKernel::create(), $logger, $server, static fn(): Request => Request::create($path), $maxRequests)->run();

        return ['output' => (string) ob_get_clean(), 'handled' => $handled];
    }

    public function testRequestScopedStateDoesNotLeakIntoTheNextRequest(): void
    {
        $result = $this->runWorker('/count', 3);

        self::assertSame('111', $result['output']); // 1,2,3 would mean the counter survived
    }

    public function testWorkerExitsAfterMaxRequestsEvenIfServerKeepsSending(): void
    {
        $result = $this->runWorker('/count', 100, maxRequests: 2);

        self::assertSame(2, $result['handled']);
    }

    public function testFailedResetStopsTheWorkerAndIsLogged(): void
    {
        $logger = new RecordingLogger();

        $result = $this->runWorker('/broken', 100, logger: $logger);

        self::assertSame(1, $result['handled']);
        self::assertSame(['critical: State reset failed, stopping worker'], $logger->records);
    }
}
