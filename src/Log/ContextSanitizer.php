<?php

declare(strict_types=1);

namespace Slimfony\Core\Log;

/**
 * Makes a log context safe to write: secret-looking keys are redacted, credentials inside URLs/DSNs are masked,
 * exceptions are flattened without trace arguments (arguments often hold secrets), and values are bounded.
 */
final class ContextSanitizer
{
    private const string SECRET_KEY = '/pass|secret|token|authorization|api[-_]?key|cookie|dsn|credential/i';
    private const int MAX_DEPTH = 5;
    private const int MAX_TRACE_FRAMES = 15;

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    public function sanitize(array $context, int $depth = 0): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[$key] = match (true) {
                \is_string($key) && 1 === preg_match(self::SECRET_KEY, $key) => '[redacted]',
                $value instanceof \Throwable => $this->exception($value),
                \is_array($value) => $depth < self::MAX_DEPTH ? $this->sanitize($value, $depth + 1) : '[max depth]',
                \is_string($value) => $this->mask($value),
                null === $value || \is_scalar($value) => $value,
                $value instanceof \Stringable => $this->mask((string) $value),
                default => '[' . get_debug_type($value) . ']',
            };
        }

        return $out;
    }

    /** Masks the password part of anything URL/DSN shaped: scheme://user:password@host */
    public function mask(string $text): string
    {
        return preg_replace('#(://[^/\s:@]+:)[^@\s]+@#', '$1[redacted]@', $text) ?? $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function exception(\Throwable $e): array
    {
        $trace = [];
        foreach (\array_slice($e->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $trace[] = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? 0) . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
        }

        $out = ['class' => $e::class, 'message' => $this->mask($e->getMessage()), 'file' => $e->getFile() . ':' . $e->getLine(), 'trace' => $trace];
        if (null !== $e->getPrevious()) {
            $out['previous'] = $e->getPrevious()::class . ': ' . $this->mask($e->getPrevious()->getMessage());
        }

        return $out;
    }
}
