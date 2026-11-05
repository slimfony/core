<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Slimfony\Core\Kernel;
use Slimfony\Core\Worker\WorkerRunner;
use Symfony\Component\HttpFoundation\Request;

final class WorkerRunnerTest extends TestCase
{
    private function kernel(): Kernel
    {
        return new Kernel(__DIR__ . '/Fixtures/services.php', __DIR__ . '/Fixtures/routes.php');
    }

    /**
     * Runs the worker loop; the fake server delivers $deliveries requests to $path, then asks the worker to stop.
     *
     * @return array{output: string, handled: int}
     */
    private function runWorker(string $path, int $deliveries, int $maxRequests = 0, ?AbstractLogger $logger = null): array
    {
        $handled = 0;
        $server = static function (callable $handler) use (&$handled, $deliveries): bool {
            $handler();

            return ++$handled < $deliveries;
        };

        ob_start();
        new WorkerRunner($this->kernel(), $logger, $server, static fn(): Request => Request::create($path), $maxRequests)->run();

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
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $critical = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if ('critical' === $level) {
                    $this->critical[] = (string) $message;
                }
            }
        };

        $result = $this->runWorker('/broken', 100, logger: $logger);

        self::assertSame(1, $result['handled']);
        self::assertSame(['State reset failed, stopping worker'], $logger->critical);
    }
}
