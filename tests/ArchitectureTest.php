<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The code rules from GUIDELINES.md that a machine can check, so a review never has to.
 */
final class ArchitectureTest extends TestCase
{
    private const string SRC = __DIR__ . '/../src';

    /**
     * @return array<string, class-string> file => class name
     */
    private static function sourceClasses(): array
    {
        $classes = [];
        $root = (string) realpath(self::SRC);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $relative = substr($file->getPathname(), \strlen($root) + 1, -4);
                $name = 'Slimfony\\Core\\' . str_replace(['/', '\\'], '\\', $relative);
                self::assertTrue(class_exists($name) || interface_exists($name) || enum_exists($name), $name . ' does not match its file name (PSR-4)');
                $classes[$file->getPathname()] = $name;
            }
        }
        self::assertNotEmpty($classes);

        return $classes;
    }

    public function testEveryFileDeclaresStrictTypes(): void
    {
        foreach (array_keys(self::sourceClasses()) as $file) {
            self::assertMatchesRegularExpression('/^<\?php\s+declare\(strict_types=1\);/', (string) file_get_contents($file), $file);
        }
    }

    public function testEveryClassIsFinalOrAbstract(): void
    {
        foreach (self::sourceClasses() as $name) {
            $class = new \ReflectionClass($name);
            if ($class->isInterface() || $class->isEnum()) {
                continue;
            }
            self::assertTrue($class->isFinal() || $class->isAbstract(), $name . ' must be final (or abstract): composition over inheritance');
        }
    }

    public function testEveryClassConstantHasANativeType(): void
    {
        foreach (self::sourceClasses() as $name) {
            foreach (new \ReflectionClass($name)->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $name) {
                    self::assertTrue($constant->hasType(), \sprintf('%s::%s needs a native type (PHP 8.3 typed constants)', $name, $constant->getName()));
                }
            }
        }
    }

    public function testEveryMethodThatImplementsOrOverridesIsMarkedWithOverride(): void
    {
        foreach (self::sourceClasses() as $name) {
            $class = new \ReflectionClass($name);
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $name || $method->isConstructor() || $method->isPrivate()) {
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
}
