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
     * @return list<string> the README and the pages of the guide
     */
    private static function pages(): array
    {
        $guide = glob(__DIR__ . '/../guide/*.md');

        return [self::README, ...(false === $guide ? [] : $guide)];
    }

    /**
     * @return list<string> every ```php block
     */
    private static function phpBlocks(): array
    {
        $blocks = [];
        foreach (self::pages() as $file) {
            preg_match_all('/^```php\n(.*?)\n```$/ms', (string) file_get_contents($file), $matches);
            array_push($blocks, ...$matches[1]);
        }
        self::assertNotEmpty($blocks, 'the README has no PHP samples');

        return $blocks;
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

    public function testTheContentsListPointsAtRealHeadingsAndMissesNone(): void
    {
        $readme = (string) file_get_contents(self::README);
        preg_match('/^\*\*Contents\*\*\n\n((?:- \[.*\n)+)/m', $readme, $list);
        self::assertNotEmpty($list, 'the README has no contents list');
        preg_match_all('/^- \[(.+?)\]\(#(.+?)\)$/m', $list[1], $items, \PREG_SET_ORDER);

        preg_match_all('/^## (.+)$/m', $readme, $headings);
        $slug = static fn(string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 -]/', '', strtolower($heading)));

        self::assertSame(array_map($slug, $headings[1]), array_column($items, 2), 'the contents list must name every section, in order, with working anchors');
    }
    public function testEveryLinkToAFileOfTheRepositoryWorksAndEveryPageOfTheGuideIsLinked(): void
    {
        foreach (self::pages() as $page) {
            preg_match_all('/\]\(([^)#\s]+)(?:#[^)]*)?\)/', (string) file_get_contents($page), $links);
            foreach ($links[1] as $target) {
                if (1 === preg_match('#^[a-z]+:#i', $target)) {
                    continue; // an address on the web
                }
                self::assertFileExists(\dirname($page) . '/' . $target, \sprintf('%s links to %s, which does not exist', basename($page), $target));
            }
        }

        $readme = (string) file_get_contents(self::README);
        foreach (self::pages() as $page) {
            if (self::README === $page) {
                continue;
            }
            self::assertStringContainsString('(guide/' . basename($page) . ')', $readme, basename($page) . ' is not linked from the README');
        }
    }
}
