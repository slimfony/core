<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

/** What the application got to read of a request body (null: it never ran). */
final class SeenBody
{
    public ?string $body = null;
}
