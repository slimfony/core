<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Exception\HttpException;
use Slimfony\Core\Http\BodySizeLimitMiddleware;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Support\TestDb;
use Slimfony\Core\Worker\WorkerRunner;

/** Nonsense settings must fail when the object is built (at boot), not misbehave under load. */
final class InvariantsTest extends TestCase
{
    /**
     * @param \Closure(): object $build
     */
    #[DataProvider('nonsenseSettings')]
    public function testNonsenseSettingsAreRejectedAtConstruction(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build();
    }

    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function nonsenseSettings(): iterable
    {
        $kernel = static fn(): Kernel => new Kernel(__DIR__ . '/Fixtures/services.php', __DIR__ . '/Fixtures/routes.php');

        yield 'retry with zero attempts' => [static fn(): object => new Retry(TestDb::connection(), maxAttempts: 0)];
        yield 'negative body size limit' => [static fn(): object => new BodySizeLimitMiddleware(-1)];
        yield 'negative idle time' => [static fn(): object => new ConnectionLifecycle(TestDb::connection(), idleSeconds: -1)];
        yield 'negative max requests' => [static fn(): object => new WorkerRunner($kernel(), maxRequests: -1)];
        yield 'negative memory limit' => [static fn(): object => new WorkerRunner($kernel(), maxMemoryBytes: -1)];
        yield 'a success status as an error' => [static fn(): object => new HttpException(200)];
    }
}
