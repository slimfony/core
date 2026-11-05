<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Worker;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Tests\Support\Attempts;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TestKernel;
use Slimfony\Core\Worker\ConsumerRunner;
use Symfony\Component\HttpFoundation\Request;

final class ConsumerRunnerTest extends TestCase
{
    public function testStateIsResetAfterEveryMessageAndTheLoopEndsWhenTheSourceIsEmpty(): void
    {
        $kernel = TestKernel::create();
        $seen = [];
        $messages = new Attempts();

        // each "message" uses a request-scoped counter: without a reset in between it would count 1, 2, 3
        new ConsumerRunner($kernel)->run(static function () use ($kernel, &$seen, $messages): bool {
            $seen[] = $kernel->handle(Request::create('/count'))->getContent();

            return ++$messages->n < 3;
        });

        self::assertSame(['1', '1', '1'], $seen);
    }

    public function testItStopsAfterMaxMessagesAndWhenAskedTo(): void
    {
        $kernel = TestKernel::create();
        $handled = new Attempts();
        new ConsumerRunner($kernel, maxMessages: 2)->run(static function () use ($handled): bool {
            ++$handled->n;

            return true; // the source never runs dry
        });
        self::assertSame(2, $handled->n);

        $handled = new Attempts();
        $runner = new ConsumerRunner($kernel);
        $runner->run(static function () use ($handled, $runner): bool {
            if (3 === ++$handled->n) {
                $runner->stop(); // what a SIGTERM handler does
            }

            return true;
        });
        self::assertSame(3, $handled->n);
    }

    public function testItStopsAboveTheMemoryLimit(): void
    {
        $handled = new Attempts();

        new ConsumerRunner(TestKernel::create(), maxMemoryBytes: 1)->run(static function () use ($handled): bool {
            ++$handled->n;

            return true;
        });

        self::assertSame(1, $handled->n);
    }

    public function testAFailedResetEndsTheLoopAndIsLogged(): void
    {
        $kernel = TestKernel::create();
        $logger = new RecordingLogger();
        $handled = new Attempts();

        new ConsumerRunner($kernel, $logger)->run(static function () use ($kernel, $handled): bool {
            ++$handled->n;
            $kernel->handle(Request::create('/broken')); // initializes a service whose reset() throws

            return true;
        });

        self::assertSame(1, $handled->n);
        self::assertSame(['critical: State reset failed, stopping consumer'], $logger->records);
    }
}
