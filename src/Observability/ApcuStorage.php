<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

use Slimfony\Core\Exception\ConfigurationException;

/**
 * Metric values in APCu: shared by all FrankenPHP worker threads, atomic increments, no locks.
 * Values are lost when the process restarts (Prometheus rate() copes with counter resets) or when APCu evicts
 * entries: give it enough `apc.shm_size`.
 *
 * @api
 */
final class ApcuStorage implements Storage
{
    public function __construct(private readonly string $prefix = 'slimfony_metrics:')
    {
        if (!\extension_loaded('apcu') || !filter_var(\ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN)) {
            throw new ConfigurationException('ApcuStorage needs the apcu extension enabled (apc.enabled=1, and apc.enable_cli=1 for CLI).');
        }
    }

    #[\Override]
    public function add(string $key, int $by): void
    {
        $key = $this->prefix . $key;
        if (false === apcu_inc($key, $by)) { // not there yet: create it, then add (add() loses a race harmlessly)
            apcu_add($key, 0);
            apcu_inc($key, $by);
        }
    }

    #[\Override]
    public function all(): array
    {
        $values = [];
        foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/') as $key => $entry) {
            if (\is_array($entry) && \is_int($entry['value'] ?? null)) {
                $values[substr($key, \strlen($this->prefix))] = $entry['value'];
            }
        }

        return $values;
    }
}
