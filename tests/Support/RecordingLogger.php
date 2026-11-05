<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Psr\Log\AbstractLogger;

/** Test double: remembers what was logged. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> "level: message" */
    public array $records = [];
    /** @var list<array<mixed>> the context of each record, same order as $records */
    public array $contexts = [];

    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = (\is_string($level) ? $level : 'unknown') . ': ' . $message;
        $this->contexts[] = $context;
    }
}
