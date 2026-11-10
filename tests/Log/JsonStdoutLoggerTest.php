<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Log;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Log\JsonStdoutLogger;
use Slimfony\Core\Log\RequestContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class JsonStdoutLoggerTest extends TestCase
{
    /**
     * @param list<string> $lines
     *
     * @return array<mixed>
     */
    private static function decode(array $lines, int $index = 0): array
    {
        self::assertArrayHasKey($index, $lines);
        self::assertStringEndsWith("\n", $lines[$index]);
        $record = json_decode($lines[$index], true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);

        return $record;
    }

    /** @param list<string> $lines */
    private static function logger(array &$lines, string $level = LogLevel::INFO, ?RequestContext $context = null): JsonStdoutLogger
    {
        return new JsonStdoutLogger($level, $context, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
    }

    public function testEveryRecordIsOneJsonLineWithInterpolationAndLevelFiltering(): void
    {
        $lines = [];
        $logger = self::logger($lines);

        $logger->debug('dropped: below the minimum level');
        $logger->warning("order {id} failed\nsecond line", ['id' => 42]);

        self::assertCount(1, $lines);
        self::assertSame(1, substr_count($lines[0], "\n"), 'a newline in the message must not break the one-record-per-line format');
        $record = self::decode($lines);
        self::assertSame('warning', $record['level']);
        self::assertSame("order 42 failed\nsecond line", $record['message']);
        self::assertIsString($record['ts']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $record['ts']);
    }

    public function testSecretsNeverReachTheLog(): void
    {
        $lines = [];
        $logger = self::logger($lines);

        $logger->error('connect to pgsql://app:hunter2@db:5432/x failed', [
            'password' => 'hunter2',
            'headers' => ['Authorization' => 'Bearer abc.def.ghi', 'accept' => 'json'],
            'dsn' => 'pgsql://app:hunter2@db/x',
            'note' => 'see pgsql://app:hunter2@db/x',
        ]);

        self::assertStringNotContainsString('hunter2', $lines[0]);
        self::assertStringNotContainsString('abc.def.ghi', $lines[0]);
        self::assertStringContainsString('json', $lines[0], 'harmless values stay');
    }

    public function testCredentialsInsideAMessageAreMaskedToo(): void
    {
        $lines = [];
        $logger = self::logger($lines);

        $logger->warning('the call failed with Authorization: Bearer abc123def456 and a token eyJhbGciOiJFZERTQSJ9.eyJzdWIiOiJhbGljZSJ9.c2lnbmF0dXJl in it');
        $logger->error('rejected', ['exception' => new \RuntimeException('HTTP 401 for Basic dXNlcjpwYXNzd29yZA==')]);

        foreach ($lines as $line) {
            self::assertStringNotContainsString('abc123def456', $line);
            self::assertStringNotContainsString('eyJhbGci', $line);
            self::assertStringNotContainsString('dXNlcjpwYXNzd29yZA', $line);
        }
        self::assertStringContainsString('Bearer [redacted]', $lines[0]);
    }

    public function testExceptionsAreLoggedWithoutTraceArguments(): void
    {
        $lines = [];
        $throwing = static function (string $password): never {
            throw new \RuntimeException('boom');
        };

        try {
            $throwing('hunter2-as-argument');
        } catch (\RuntimeException $e) {
            self::logger($lines)->error('failed', ['exception' => $e]);
        }

        self::assertStringNotContainsString('hunter2-as-argument', $lines[0]);
        $record = self::decode($lines);
        self::assertIsArray($record['context']);
        self::assertIsArray($record['context']['exception']);
        self::assertSame(\RuntimeException::class, $record['context']['exception']['class']);
    }

    public function testRequestAndTraceIdTagEveryLineOfARequestAndAreGoneAfterReset(): void
    {
        $lines = [];
        $context = new RequestContext();
        $logger = self::logger($lines, context: $context);
        $middleware = new RequestIdMiddleware($context);

        $middleware->process(
            Request::create('/', server: ['HTTP_X_REQUEST_ID' => 'req-12345678', 'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']),
            static function () use ($logger): Response {
                $logger->info('inside the request');

                return new Response();
            },
        );
        $context->reset(); // what the StateResetter does after the request
        $logger->info('between requests');

        $inside = self::decode($lines, 0);
        self::assertSame('req-12345678', $inside['request_id']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $inside['trace_id']);
        self::assertArrayNotHasKey('request_id', self::decode($lines, 1), 'ids must not leak into the next request');
    }
    public function testABrokenLogPipeNeverBreaksTheRequest(): void
    {
        $logger = new JsonStdoutLogger(sink: static function (string $line): void {
            throw new \RuntimeException('the pipe is closed');
        });

        $logger->error('something happened');

        $this->addToAssertionCount(1); // reaching this line is the point: no exception came out of the logger
    }
}
