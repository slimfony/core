<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Config;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Build\Compiler;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Config\Trust;
use Slimfony\Core\Kernel;
use Symfony\Component\HttpFoundation\Request;

final class TrustTest extends TestCase
{
    protected function tearDown(): void
    {
        // Symfony keeps this in static state: never let it leak into other tests
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        Request::setTrustedHosts([]);
    }

    private static function forwarded(string $remoteAddr): Request
    {
        return Request::create('/', server: ['REMOTE_ADDR' => $remoteAddr, 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
    }

    public function testForwardedHeadersAreIgnoredUnlessTheDirectPeerIsATrustedProxy(): void
    {
        Trust::apply(new Env([]));
        self::assertSame('10.1.2.3', self::forwarded('10.1.2.3')->getClientIp(), 'secure default: nothing trusted');

        Trust::apply(new Env(['TRUSTED_PROXIES' => '10.0.0.0/8']));
        self::assertSame('203.0.113.9', self::forwarded('10.1.2.3')->getClientIp());
        self::assertSame('192.0.2.1', self::forwarded('192.0.2.1')->getClientIp(), 'a spoofing client cannot set X-Forwarded-For');
    }

    public function testRequestsForAnUntrustedHostAreAnswered400NotServed(): void
    {
        Trust::apply(new Env(['TRUSTED_HOSTS' => 'api.example.com']));
        $kernel = new Kernel(__DIR__ . '/../Fixtures/services.php', __DIR__ . '/../Fixtures/routes.php');

        self::assertSame(200, $kernel->handle(Request::create('http://api.example.com/hello/x'))->getStatusCode());
        self::assertSame(400, $kernel->handle(Request::create('http://evil.example.net/hello/x'))->getStatusCode());
    }

    public function testNoSecretValueEverEndsUpInTheCompiledCache(): void
    {
        $dir = sys_get_temp_dir() . '/slimfony-test-' . bin2hex(random_bytes(6));
        putenv('SLIMFONY_TEST_SECRET=top-secret-value-123');

        try {
            Compiler::warmup(__DIR__ . '/../Fixtures/Bad/secret_in_env.php', __DIR__ . '/../Fixtures/Bad/empty_routes.php', $dir);

            $files = glob($dir . '/*');
            self::assertNotEmpty($files);
            foreach ($files as $file) {
                self::assertStringNotContainsString('top-secret-value-123', (string) file_get_contents($file));
                unlink($file);
            }
            rmdir($dir);
        } finally {
            putenv('SLIMFONY_TEST_SECRET');
        }
    }
}
