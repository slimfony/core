<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Observability;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Observability\ApcuStorage;

final class ApcuStorageTest extends TestCase
{
    private string $prefix = '';

    protected function setUp(): void
    {
        if (!\extension_loaded('apcu') || !filter_var(\ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN) || !filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOLEAN)) {
            self::markTestSkipped('Needs APCu with apc.enable_cli=1 (CI always has it).');
        }
        $this->prefix = 'slimfony_test_' . bin2hex(random_bytes(4)) . ':';
    }

    protected function tearDown(): void
    {
        if ('' !== $this->prefix) {
            apcu_delete(new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/'));
        }
    }

    public function testValuesAccumulateAndOnlyOurOwnKeysAreReturnedWithoutTheirPrefix(): void
    {
        $storage = new ApcuStorage($this->prefix);
        apcu_store('slimfony_test_foreign_key', 99); // someone else's APCu entry must be ignored

        $storage->add('c|requests|GET', 1);
        $storage->add('c|requests|GET', 2);
        $storage->add('s|time|', 1_500_000);

        self::assertEquals(['c|requests|GET' => 3, 's|time|' => 1_500_000], $storage->all()); // APCu iteration order is unspecified

        apcu_delete('slimfony_test_foreign_key');
    }
}
