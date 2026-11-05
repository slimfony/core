<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> "level: message" */
    public array $records = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = (is_string($level) ? $level : 'unknown') . ': ' . $message;
    }
}
