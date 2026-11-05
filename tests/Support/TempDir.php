<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Support;

/**
 * A unique temporary directory path that is removed (with its files) when the object goes away,
 * so tests need no tearDown boilerplate. The directory itself is created by whoever writes into it.
 */
final class TempDir
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'slimfony-test-' . bin2hex(random_bytes(6));
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        $files = glob($this->path . '/*');

        return false === $files ? [] : $files;
    }

    public function __destruct()
    {
        foreach ($this->files() as $file) {
            unlink($file);
        }
        if (is_dir($this->path)) {
            rmdir($this->path);
        }
    }
}
