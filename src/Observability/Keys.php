<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

/**
 * Storage key layout: "<kind>|<metric name>|<label values, url-encoded, comma separated>[|<bucket index>]".
 * Pure functions, no state.
 *
 * @internal
 */
final class Keys
{
    public const string COUNTER = 'c';
    public const string BUCKET = 'b';
    public const string COUNT = 'n';
    public const string SUM_MICROSECONDS = 's';

    private function __construct() {}

    /**
     * @param list<string> $labelValues
     */
    public static function of(string $kind, string $name, array $labelValues, ?int $bucket = null): string
    {
        $key = $kind . '|' . $name . '|' . implode(',', array_map(rawurlencode(...), $labelValues));

        return null === $bucket ? $key : $key . '|' . $bucket;
    }

    /**
     * @return array{kind: string, name: string, labels: string, bucket: int|null}|null null for a key that is not ours; labels stay url-encoded
     */
    public static function parse(string $key): ?array
    {
        $parts = explode('|', $key);
        if (\count($parts) < 3 || \count($parts) > 4) {
            return null;
        }

        return [
            'kind' => $parts[0],
            'name' => $parts[1],
            'labels' => $parts[2],
            'bucket' => isset($parts[3]) ? (int) $parts[3] : null,
        ];
    }
}
