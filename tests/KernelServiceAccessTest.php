<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Kernel;
use Slimfony\Core\Tests\Fixtures\Greeter;
use Slimfony\Core\Tests\Support\FakeAuthenticator;
use Slimfony\Core\Tests\Support\TestKernel;

/** Code that runs without an HTTP request (a consumer, a relay) reaches the container through Kernel::get(). */
final class KernelServiceAccessTest extends TestCase
{
    public function testAPublicServiceCanBeFetchedWithoutAnyRequest(): void
    {
        $authenticator = new FakeAuthenticator([]);
        $kernel = new Kernel(
            TestKernel::FIXTURES . '/Auth/services.php',
            TestKernel::FIXTURES . '/Auth/routes.php',
            synthetic: [Authenticator::class => $authenticator],
        );

        self::assertSame($authenticator, $kernel->get(Authenticator::class));
    }

    public function testPrivateAndUnknownServicesAreRefusedWithAHelpfulError(): void
    {
        $kernel = TestKernel::create();

        foreach ([Greeter::class /* private: only a controller uses it */, 'no.such.service'] as $id) {
            try {
                $kernel->get($id);
                self::fail(\sprintf('"%s" must not be reachable.', $id));
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('setPublic(true)', $e->getMessage());
            }
        }
    }
}
