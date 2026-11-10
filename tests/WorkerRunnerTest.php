<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\InMemoryStorage;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TestKernel;
use Slimfony\Core\Worker\GarbageCollection;
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
    #[RunInSeparateProcess]
    public function testClassicModeServesOneRequestInsteadOfFailing(): void
    {
        require_once __DIR__ . '/stubs/frankenphp_stub.php'; // FrankenPHP's classic mode: the function exists and throws

        ob_start();
        new WorkerRunner(TestKernel::create(), requestFactory: static fn(): Request => Request::create('/count'))->run();

        self::assertSame('1', ob_get_clean());
    }

    #[RunInSeparateProcess]
    public function testAnErrorThatIsNotTheClassicModeSignalIsNotHidden(): void
    {
        require_once __DIR__ . '/stubs/frankenphp_stub.php';
        $GLOBALS['slimfony_frankenphp_stub_message'] = 'something else is wrong';

        try {
            $runner = new WorkerRunner(TestKernel::create(), requestFactory: static fn(): Request => Request::create('/count'));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('something else is wrong');
            $runner->run();
        } finally {
            unset($GLOBALS['slimfony_frankenphp_stub_message']);
        }
    }

    public function testDeferredGcIsOffWhileARequestIsHandledAndBackOnAfterwards(): void
    {
        $insideRequest = [];
        $server = static function (callable $handler) use (&$insideRequest): bool {
            $insideRequest[] = gc_enabled();
            $handler();

            return \count($insideRequest) < 3;
        };

        ob_start();
        new WorkerRunner(TestKernel::create(), handleRequest: $server, requestFactory: static fn(): Request => Request::create('/count'), gc: new GarbageCollection(deferred: true))->run();
        ob_end_clean();

        self::assertSame([false, false, false], $insideRequest);
        self::assertTrue(gc_enabled(), 'the collector is back on when the worker ends');
    }

    public function testDeferredGcCollectsBetweenRequestsAndCountsWhatItDid(): void
    {
        $registry = new MetricsRegistry(new InMemoryStorage());
        $served = 0;
        $server = static function (callable $handler) use (&$served): bool {
            for ($i = 0; $i < 500; ++$i) { // the garbage a request leaves: objects that refer to each other, so only the collector can free them
                $a = new \stdClass();
                $b = new \stdClass();
                $a->other = $b;
                $b->other = $a;
            }
            $handler();

            return ++$served < 3;
        };

        gc_collect_cycles();
        ob_start();
        new WorkerRunner(
            TestKernel::create(),
            handleRequest: $server,
            requestFactory: static fn(): Request => Request::create('/count'),
            gc: new GarbageCollection(
                deferred: true,
                rootsThreshold: 100,
                runs: $registry->counter('gc_runs_total', 'Collector runs.'),
                collected: $registry->counter('gc_collected_total', 'Cycles freed.'),
            ),
        )->run();
        ob_end_clean();

        $out = $registry->render();
        self::assertMatchesRegularExpression('/^gc_runs_total [1-9]\d*$/m', $out);
        self::assertMatchesRegularExpression('/^gc_collected_total [1-9]\d*$/m', $out);
    }

    public function testNonsenseGarbageCollectionSettingsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GarbageCollection(rootsThreshold: 0);
    }
}
