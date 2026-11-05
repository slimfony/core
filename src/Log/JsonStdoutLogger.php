<?php

declare(strict_types=1);

namespace Slimfony\Core\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * One JSON object per line on stdout (what Kubernetes and every log shipper expects).
 *
 * - request_id / trace_id are added automatically while a request is being served (see RequestContext).
 * - Values under keys that look like secrets are replaced, and credentials inside URLs/DSNs are masked.
 * - Exceptions are written without trace arguments (arguments often hold secrets).
 * - It never throws: logging must not be able to break a request.
 */
final class JsonStdoutLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];
    private const SECRET_KEY = '/pass|secret|token|authorization|api[-_]?key|cookie|dsn|credential/i';

    /** @var \Closure(string): void */
    private readonly \Closure $sink;
    private readonly int $minLevel;
    /** @var resource|null process-lifetime handle, opened on first write (not request state) */
    private mixed $stdout = null;

    /**
     * @param (\Closure(string): void)|null $sink receives one finished line (with newline); defaults to stdout
     */
    public function __construct(
        string $minLevel = LogLevel::INFO,
        private readonly ?RequestContext $context = null,
        ?\Closure $sink = null,
    ) {
        $this->minLevel = self::LEVELS[$minLevel] ?? throw new \InvalidArgumentException(\sprintf('Unknown log level "%s".', $minLevel));
        $this->sink = $sink ?? $this->writeToStdout(...);
    }

    private function writeToStdout(string $line): void
    {
        if (null === $this->stdout) {
            $handle = fopen('php://stdout', 'w');
            if (false === $handle) {
                return;
            }
            $this->stdout = $handle;
        }
        fwrite($this->stdout, $line);
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $level = \is_string($level) ? $level : 'unknown';
        if ((self::LEVELS[$level] ?? self::LEVELS[LogLevel::ERROR]) < $this->minLevel) {
            return;
        }

        $safe = self::sanitize($context);
        $record = [
            'ts' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'message' => self::interpolate(self::mask((string) $message), $safe),
        ];
        if (null !== $this->context?->requestId) {
            $record['request_id'] = $this->context->requestId;
        }
        if (null !== $this->context?->traceId) {
            $record['trace_id'] = $this->context->traceId;
        }
        if ([] !== $safe) {
            $record['context'] = $safe;
        }

        try {
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            ($this->sink)((false === $line ? '{"level":"error","message":"log record could not be encoded"}' : $line) . "\n");
        } catch (\Throwable) {
            // a broken log pipe must never break the request
        }
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function sanitize(array $context, int $depth = 0): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (\is_string($key) && 1 === preg_match(self::SECRET_KEY, $key)) {
                $out[$key] = '[redacted]';
            } elseif ($value instanceof \Throwable) {
                $out[$key] = self::exception($value);
            } elseif (\is_array($value)) {
                $out[$key] = $depth < 5 ? self::sanitize($value, $depth + 1) : '[max depth]';
            } elseif (\is_string($value)) {
                $out[$key] = self::mask($value);
            } elseif (null === $value || \is_scalar($value)) {
                $out[$key] = $value;
            } elseif ($value instanceof \Stringable) {
                $out[$key] = self::mask((string) $value);
            } else {
                $out[$key] = '[' . get_debug_type($value) . ']';
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function exception(\Throwable $e): array
    {
        $trace = [];
        foreach (\array_slice($e->getTrace(), 0, 15) as $frame) {
            $trace[] = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? 0) . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
        }
        $out = ['class' => $e::class, 'message' => self::mask($e->getMessage()), 'file' => $e->getFile() . ':' . $e->getLine(), 'trace' => $trace];
        if (null !== $e->getPrevious()) {
            $out['previous'] = $e->getPrevious()::class . ': ' . self::mask($e->getPrevious()->getMessage());
        }

        return $out;
    }

    /** Masks the password part of anything URL/DSN shaped: scheme://user:password@host */
    private static function mask(string $text): string
    {
        return preg_replace('#(://[^/\s:@]+:)[^@\s]+@#', '$1[redacted]@', $text) ?? $text;
    }

    /**
     * @param array<mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (\is_string($key) && (\is_scalar($value) || null === $value)) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return [] === $replace ? $message : strtr($message, $replace);
    }
}
