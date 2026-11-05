<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

/** Counts how often a retried callable ran. */
final class Attempts
{
    public int $n = 0;
}
