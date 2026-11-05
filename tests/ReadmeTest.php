<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Tests\Support\RecordingLogger;
use Slimfony\Core\Tests\Support\TempDir;
use Symfony\Component\HttpFoundation\Request;

/**
 * The README is the first thing a new service author copies. Its code samples must therefore be valid PHP,
 * and the quickstart application must actually work.
 */
final class ReadmeTest extends TestCase
{
    private const string README = __DIR__ . '/../README.md';

    /**
     * @return list<string> every ```php block
     */
    private static function phpBlocks(): array
    {
        preg_match_all('/^```php\n(.*?)\n```$/ms', (string) file_get_contents(self::README), $matches);
        self::assertNotEmpty($matches[1], 'the README has no PHP samples');

        return $matches[1];
    }

    /**
     * @return array<string, string> file name shown above the block => its code
     */
    private static function quickstartFiles(): array
    {
        preg_match_all('/^`([^`\n]+\.php)`[^\n]*\n\n```php\n(.*?)\n```$/ms', (string) file_get_contents(self::README), $matches, \PREG_SET_ORDER);

        $files = [];
        foreach ($matches as $match) {
            $files[$match[1]] = $match[2];
        }

        return $files;
    }

    public function testEveryPhpSampleIsSyntacticallyValid(): void
    {
        foreach (self::phpBlocks() as $index => $code) {
            try {
                \PhpToken::tokenize($code, \TOKEN_PARSE);
            } catch (\ParseError $e) {
                self::fail(\sprintf('PHP sample #%d in the README does not parse: %s', $index + 1, $e->getMessage()));
            }
        }
    }

    public function testTheQuickstartApplicationActuallyWorks(): void
    {
        $files = self::quickstartFiles();
        foreach (['public/index.php', 'config/services.php', 'config/routes.php'] as $required) {
            self::assertArrayHasKey($required, $files);
        }
        $controller = null;
        foreach ($files as $name => $code) {
            if (str_starts_with($name, 'src/')) {
                $controller = $code;
            }
        }
        self::assertNotNull($controller, 'the quickstart needs its controller sample');

        $dir = new TempDir();
        mkdir($dir->path, 0o755, true);
        file_put_contents($dir->path . '/Hello.php', $controller);
        file_put_contents($dir->path . '/services.php', $files['config/services.php']);
        file_put_contents($dir->path . '/routes.php', $files['config/routes.php']);
        require $dir->path . '/Hello.php';

        // what public/index.php hands over as synthetic services
        $context = new RequestContext();
        $kernel = new Kernel(
            $dir->path . '/services.php',
            $dir->path . '/routes.php',
            logger: new RecordingLogger(),
            synthetic: [RequestContext::class => $context, LoggerInterface::class => new RecordingLogger()],
        );

        $response = $kernel->handle(Request::create('/hello/world'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello world', $response->getContent());
        self::assertNotNull($response->headers->get('X-Request-Id'), 'the request id middleware from the quickstart is active');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame(405, $kernel->handle(Request::create('/hello/world', 'POST'))->getStatusCode());
    }
}
