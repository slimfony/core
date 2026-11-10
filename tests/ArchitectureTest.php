<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The code rules from GUIDELINES.md that a machine can check, so a review never has to.
 * They apply to the library and to the test support code alike.
 */
final class ArchitectureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> directory, namespace
     */
    public static function codeBases(): iterable
    {
        yield 'src' => [__DIR__ . '/../src', 'Slimfony\\Core\\'];
        yield 'tests/Support' => [__DIR__ . '/Support', 'Slimfony\\Core\\Tests\\Support\\'];
    }

    /**
     * @return array<string, class-string> file => class name
     */
    private static function classesIn(string $directory, string $namespace): array
    {
        $classes = [];
        $root = (string) realpath($directory);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $name = $namespace . str_replace('/', '\\', str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1, -4)));
                self::assertTrue(class_exists($name) || interface_exists($name) || enum_exists($name), $name . ' does not match its file name (PSR-4)');
                $classes[$file->getPathname()] = $name;
            }
        }
        self::assertNotEmpty($classes);

        return $classes;
    }

    #[DataProvider('codeBases')]
    public function testEveryFileDeclaresStrictTypes(string $directory, string $namespace): void
    {
        foreach (array_keys(self::classesIn($directory, $namespace)) as $file) {
            self::assertMatchesRegularExpression('/^<\?php\s+declare\(strict_types=1\);/', (string) file_get_contents($file), $file);
        }
    }

    #[DataProvider('codeBases')]
    public function testEveryClassIsFinalOrAbstract(string $directory, string $namespace): void
    {
        foreach (self::classesIn($directory, $namespace) as $name) {
            $class = new \ReflectionClass($name);
            if ($class->isInterface() || $class->isEnum()) {
                continue;
            }
            self::assertTrue($class->isFinal() || $class->isAbstract(), $name . ' must be final (or abstract): composition over inheritance');
        }
    }

    #[DataProvider('codeBases')]
    public function testEveryClassConstantHasANativeType(string $directory, string $namespace): void
    {
        foreach (self::classesIn($directory, $namespace) as $name) {
            foreach (new \ReflectionClass($name)->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $name && !$constant->isEnumCase()) { // an enum case is not a constant with a type
                    self::assertTrue($constant->hasType(), \sprintf('%s::%s needs a native type (PHP 8.3 typed constants)', $name, $constant->getName()));
                }
            }
        }
    }

    #[DataProvider('codeBases')]
    public function testEveryMethodThatImplementsOrOverridesIsMarkedWithOverride(string $directory, string $namespace): void
    {
        foreach (self::classesIn($directory, $namespace) as $name) {
            $class = new \ReflectionClass($name);
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $name || $method->isConstructor() || $method->isPrivate() || ($class->isEnum() && \in_array($method->getName(), ['cases', 'from', 'tryFrom'], true))) { // the built-in methods of an enum
                    continue;
                }
                $parent = $class->getParentClass();
                $inherited = (false !== $parent && $parent->hasMethod($method->getName()))
                    || [] !== array_filter($class->getInterfaces(), static fn(\ReflectionClass $i): bool => $i->hasMethod($method->getName()));
                if ($inherited) {
                    self::assertNotEmpty($method->getAttributes(\Override::class), \sprintf('%s::%s() needs #[\Override]', $name, $method->getName()));
                }
            }
        }
    }

    public function testEveryLibraryClassStatesWhetherItIsPublicApiOrInternal(): void
    {
        foreach (self::classesIn(__DIR__ . '/../src', 'Slimfony\\Core\\') as $name) {
            $doc = new \ReflectionClass($name)->getDocComment();
            self::assertTrue(false !== $doc && 1 === preg_match('/@(api|internal)\b/', $doc), $name . ' needs @api or @internal (the SemVer promise covers @api only)');
        }
    }
}
