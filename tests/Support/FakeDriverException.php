<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

use Doctrine\DBAL\Driver\Exception as DriverException;

/** The driver-level error DBAL wraps into its own exception types (deadlock, lost connection, ...). */
final class FakeDriverException extends \Exception implements DriverException
{
    #[\Override]
    public function getSQLState(): ?string
    {
        return null;
    }
}
