<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Worker\WorkerRunner;
use Symfony\Component\HttpFoundation\Request;

/** The safety nets of the worker loop. */
final class WorkerSafetyTest extends TestCase
{
    private function kernel(): Kernel
    {
        return new Kernel(__DIR__ . '/Fixtures/services.php', __DIR__ . '/Fixtures/routes.php');
    }

    public function testStrayOutputFromAControllerIsDiscardedAndLoggedNotMixedIntoTheResponse(): void
    {
        $logger = new RecordingLogger();
        $server = static function (callable $handler): bool {
            $handler();

            return false;
        };

        ob_start();
        (new WorkerRunner($this->kernel(), $logger, $server, static fn(): Request => Request::create('/chatty')))->run();
        $output = (string) ob_get_clean();

        self::assertSame('ok', $output);
        self::assertSame(['warning: Controller wrote directly to the output; discarded'], $logger->records);
    }

    public function testWorkerStopsWhenMemoryGrowsPastTheLimitEvenIfTheServerKeepsSending(): void
    {
        $handled = 0;
        $server = static function (callable $handler) use (&$handled): bool {
            $handler();
            ++$handled;

            return true;
        };

        ob_start();
        (new WorkerRunner($this->kernel(), null, $server, static fn(): Request => Request::create('/count'), maxMemoryBytes: 1))->run();
        ob_end_clean();

        self::assertSame(1, $handled);
    }

    public function testWithoutFrankenPhpItServesExactlyOneRequestLikeAFrontController(): void
    {
        self::assertFalse(\function_exists('frankenphp_handle_request'), 'this test is for plain CLI/FPM');

        ob_start();
        (new WorkerRunner($this->kernel(), requestFactory: static fn(): Request => Request::create('/count')))->run();

        self::assertSame('1', ob_get_clean());
    }
}
