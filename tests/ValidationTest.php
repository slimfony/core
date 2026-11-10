<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Log\ContextSanitizer;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\InMemoryStorage;
use Slimfony\Core\Worker\GarbageCollection;

/** What a caller gets for arguments that cannot be right: an error at once, not a wrong number later. */
final class ValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): mixed, class-string<\Throwable>}>
     */
    public static function mistakes(): iterable
    {
        $registry = static fn(): MetricsRegistry => new MetricsRegistry(new InMemoryStorage());

        yield 'a counter step below zero' => [static fn() => $registry()->counter('a_total', 'A.')->inc([], -1), \InvalidArgumentException::class];
        yield 'a counter with a label value too many' => [static fn() => $registry()->counter('a_total', 'A.')->inc(['x']), \InvalidArgumentException::class];
        yield 'a histogram with a label value too few' => [static fn() => $registry()->histogram('a_seconds', 'A.', ['route'])->observe(0.1), \InvalidArgumentException::class];
        yield 'a histogram without buckets' => [static fn() => $registry()->histogram('a_seconds', 'A.', [], []), ConfigurationException::class];
        yield 'a garbage collection every negative requests' => [static fn() => new GarbageCollection(every: -1), \InvalidArgumentException::class];
        yield 'a garbage collection threshold of zero' => [static fn() => new GarbageCollection(rootsThreshold: 0), \InvalidArgumentException::class];
        yield 'a required integer that is not set' => [static fn() => new Env([])->int('PORT'), ConfigurationException::class];
        yield 'a required string that is not set' => [static fn() => new Env([])->string('DSN'), ConfigurationException::class];
    }

    /**
     * @param \Closure(): mixed        $mistake
     * @param class-string<\Throwable> $error
     */
    #[DataProvider('mistakes')]
    public function testItIsRefusedAtOnce(\Closure $mistake, string $error): void
    {
        $this->expectException($error);

        $mistake();
    }

    public function testObjectsInALogContextAreWrittenAsTextOrAsTheirType(): void
    {
        $text = new class implements \Stringable {
            public function __toString(): string
            {
                return 'see https://user:hunter2@host/';
            }
        };

        $out = new ContextSanitizer()->sanitize(['text' => $text, 'object' => new \stdClass(), 'error' => new \RuntimeException('outer', 0, new \LogicException('inner Bearer abc.def.ghi'))]);

        self::assertIsString($out['text']);
        self::assertStringNotContainsString('hunter2', $out['text']);
        self::assertSame('[stdClass]', $out['object']);
        self::assertIsArray($out['error']);
        $previous = $out['error']['previous'];
        self::assertIsString($previous);
        self::assertStringStartsWith('LogicException: inner', $previous);
        self::assertStringNotContainsString('abc.def.ghi', $previous);
    }

    public function testAnUnreadableSecretFileIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);

        new Env(['DB_PASSWORD_FILE' => '/no/such/file'])->string('DB_PASSWORD');
    }
}
