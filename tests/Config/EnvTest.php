<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Config;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Exception\ConfigurationException;

final class EnvTest extends TestCase
{
    public function testSecretFileIsReadAndTheTrailingNewlineDropped(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'slimfony-secret');
        self::assertIsString($file);
        file_put_contents($file, "s3cr3t\n");

        try {
            self::assertSame('s3cr3t', new Env(['DB_PASSWORD_FILE' => $file])->string('DB_PASSWORD'));
        } finally {
            unlink($file);
        }
    }

    public function testAmbiguousSecretSourceFailsLoudlyAtBoot(): void
    {
        $this->expectException(ConfigurationException::class);

        new Env(['X' => 'a', 'X_FILE' => '/irrelevant'])->get('X');
    }

    public function testUnreadableSecretFileFailsLoudlyAtBoot(): void
    {
        $this->expectException(ConfigurationException::class);

        new Env(['X_FILE' => '/nonexistent/secret'])->get('X');
    }

    public function testTypedAccessIsStrictInsteadOfGuessing(): void
    {
        $env = new Env(['PORT' => '8080', 'BAD_PORT' => '80 80', 'DEBUG' => 'yes', 'BAD_FLAG' => 'maybe']);

        self::assertSame(8080, $env->int('PORT'));
        self::assertTrue($env->bool('DEBUG'));
        self::assertFalse($env->bool('MISSING'), 'defaults to false (secure default for APP_DEBUG)');

        foreach ([static fn() => $env->int('BAD_PORT'), static fn() => $env->bool('BAD_FLAG'), static fn() => $env->string('MISSING')] as $read) {
            try {
                $read();
                self::fail('Invalid or missing configuration must not be silently accepted.');
            } catch (ConfigurationException) {
            }
        }
    }
}
