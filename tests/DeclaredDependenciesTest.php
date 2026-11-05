<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The library may only use packages it declares in `require`. Without this, code can silently lean on a package that
 * happens to be installed as somebody else's dependency, and break when that dependency changes.
 */
final class DeclaredDependenciesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/..';

    /**
     * @return array<string, string> PSR-4 namespace prefix => package name, for every installed package
     */
    private static function installedNamespaces(): array
    {
        $installed = json_decode((string) file_get_contents(self::ROOT . '/vendor/composer/installed.json'), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($installed);
        self::assertIsArray($installed['packages'] ?? null);

        $map = [];
        foreach ($installed['packages'] as $package) {
            self::assertIsArray($package);
            $name = $package['name'] ?? null;
            $psr4 = \is_array($package['autoload'] ?? null) ? ($package['autoload']['psr-4'] ?? []) : [];
            if (\is_string($name) && \is_array($psr4)) {
                foreach (array_keys($psr4) as $prefix) {
                    $map[(string) $prefix] = $name;
                }
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function declaredPackages(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);

        return array_keys($composer['require']);
    }

    /**
     * @return list<string> optional packages: usable only inside src/Auth/Jwt, which fails at boot without them
     */
    private static function optionalPackages(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['suggest'] ?? null);

        return array_keys($composer['suggest']);
    }

    public function testEverythingUnderSrcOnlyUsesDeclaredPackages(): void
    {
        $namespaces = self::installedNamespaces();
        uksort($namespaces, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a)); // longest prefix wins
        $declared = self::declaredPackages();
        $optional = self::optionalPackages();

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            preg_match_all('/^use ([A-Za-z0-9_\\\\]+)/m', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $symbol) {
                if (str_starts_with($symbol, 'Slimfony\\Core\\')) {
                    continue;
                }
                $package = null;
                foreach ($namespaces as $prefix => $name) {
                    if (str_starts_with($symbol . '\\', $prefix) || str_starts_with($symbol, $prefix)) {
                        $package = $name;

                        break;
                    }
                }
                self::assertNotNull($package, \sprintf('%s (used in %s) does not belong to any installed package', $symbol, $file->getBasename()));
                $inOptionalModule = str_contains(str_replace('\\', '/', $file->getPathname()), '/src/Auth/Jwt/');
                self::assertTrue(
                    \in_array($package, $declared, true) || ($inOptionalModule && \in_array($package, $optional, true)),
                    \sprintf('%s uses %s from %s, which is not in composer.json "require" (a "suggest" package may only be used under src/Auth/Jwt)', $file->getBasename(), $symbol, $package),
                );
            }
        }
    }
}
