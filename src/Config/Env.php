<?php

declare(strict_types=1);

namespace Slimfony\Core\Config;

/**
 * Typed access to environment variables (12-factor), with Docker/Kubernetes secret-file support:
 * for any NAME you may set NAME_FILE=/run/secrets/name instead, and the file content is used.
 *
 * Read configuration once at boot and keep the values; never log what this returns for secrets.
 */
final class Env
{
    /**
     * @param array<string, string> $vars
     */
    public function __construct(private readonly array $vars) {}

    public static function fromGlobals(): self
    {
        return new self(getenv());
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $file = $this->vars[$name . '_FILE'] ?? null;
        if (null === $file) {
            return $this->vars[$name] ?? $default;
        }
        if (isset($this->vars[$name])) {
            throw new \LogicException(\sprintf('Set either %1$s or %1$s_FILE, not both.', $name));
        }

        $content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
        if (false === $content) {
            throw new \RuntimeException(\sprintf('%s_FILE points to an unreadable file.', $name));
        }

        return rtrim($content, "\r\n");
    }

    public function string(string $name, ?string $default = null): string
    {
        return $this->get($name, $default) ?? throw new \RuntimeException(\sprintf('Missing required environment variable %s.', $name));
    }

    public function int(string $name, ?int $default = null): int
    {
        $raw = $this->get($name);
        if (null === $raw) {
            return $default ?? throw new \RuntimeException(\sprintf('Missing required environment variable %s.', $name));
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return \is_int($value) ? $value : throw new \RuntimeException(\sprintf('%s must be an integer.', $name));
    }

    public function bool(string $name, bool $default = false): bool
    {
        $raw = $this->get($name);
        if (null === $raw) {
            return $default;
        }

        return match (strtolower($raw)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => throw new \RuntimeException(\sprintf('%s must be a boolean (1/0, true/false, yes/no, on/off).', $name)),
        };
    }
}
