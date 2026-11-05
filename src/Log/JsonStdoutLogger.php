<?php

declare(strict_types=1);

namespace Slimfony\Core\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * One JSON object per line on stdout (what Kubernetes and every log shipper expects).
 *
 * - request_id / trace_id are added automatically while a request is being served (see RequestContext).
 * - Context goes through ContextSanitizer: no secrets, no trace arguments.
 * - It never throws: logging must not be able to break a request.
 *
 * @api
 */
final class JsonStdoutLogger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

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
        private readonly ContextSanitizer $sanitizer = new ContextSanitizer(),
    ) {
        $this->minLevel = self::LEVELS[$minLevel] ?? throw new \InvalidArgumentException(\sprintf('Unknown log level "%s".', $minLevel));
        $this->sink = $sink ?? $this->writeToStdout(...);
    }

    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $level = \is_string($level) ? $level : 'unknown';
        if ((self::LEVELS[$level] ?? self::LEVELS[LogLevel::ERROR]) < $this->minLevel) {
            return;
        }

        $safe = $this->sanitizer->sanitize($context);
        $record = [
            'ts' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'message' => self::interpolate($this->sanitizer->mask((string) $message), $safe),
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
            $line = json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
            ($this->sink)((false === $line ? '{"level":"error","message":"log record could not be encoded"}' : $line) . "\n");
        } catch (\Throwable) {
            // a broken log pipe must never break the request
        }
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
