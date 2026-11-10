<?php

declare(strict_types=1);

namespace Slimfony\Core\Observability;

use Slimfony\Core\Exception\ConfigurationException;

/**
 * Declares the metrics (once, at boot, so names are validated and the hot path is not) and renders them in the
 * Prometheus text exposition format. Only declared metrics are rendered.
 *
 * @api
 */
final class MetricsRegistry
{
    /** seconds; sub-millisecond resolution at the low end because the core aims at p95 < 1 ms */
    public const array DEFAULT_BUCKETS = [0.0001, 0.00025, 0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0];

    /** @var array<string, array{type: string, help: string, labels: list<string>, buckets: list<float>}> */
    private array $definitions = [];

    public function __construct(private readonly Storage $storage) {}

    /**
     * @param list<string> $labelNames
     */
    public function counter(string $name, string $help, array $labelNames = []): Counter
    {
        $this->define($name, 'counter', $help, $labelNames, []);

        return new Counter($this->storage, $name, $labelNames);
    }

    /**
     * @param list<string> $labelNames
     */
    public function gauge(string $name, string $help, array $labelNames = []): Gauge
    {
        $this->define($name, 'gauge', $help, $labelNames, []);

        return new Gauge($this->storage, $name, $labelNames);
    }

    /**
     * @param list<string> $labelNames
     * @param list<float>  $buckets    upper bounds in seconds, strictly ascending
     */
    public function histogram(string $name, string $help, array $labelNames = [], array $buckets = self::DEFAULT_BUCKETS): Histogram
    {
        if ([] === $buckets) {
            throw new ConfigurationException(\sprintf('Histogram %s needs at least one bucket.', $name));
        }
        foreach ($buckets as $i => $upperBound) {
            if ($i > 0 && $upperBound <= $buckets[$i - 1]) {
                throw new ConfigurationException(\sprintf('The buckets of histogram %s must be strictly ascending.', $name));
            }
        }
        if (\in_array('le', $labelNames, true)) {
            throw new ConfigurationException('"le" is reserved for histogram buckets.');
        }
        $this->define($name, 'histogram', $help, $labelNames, $buckets);

        return new Histogram($this->storage, $name, $labelNames, $buckets);
    }

    public function render(): string
    {
        $samples = ['counter' => [], 'gauge' => [], 'bucket' => [], 'sum' => []];
        foreach ($this->storage->all() as $key => $value) {
            $parsed = Keys::parse($key);
            if (null === $parsed || !isset($this->definitions[$parsed['name']])) {
                continue;
            }
            $labels = 'L' . $parsed['labels'];
            match ($parsed['kind']) {
                Keys::COUNTER => $samples['counter'][$parsed['name']][$labels] = $value,
                Keys::GAUGE => $samples['gauge'][$parsed['name']][$labels] = $value,
                Keys::BUCKET => $samples['bucket'][$parsed['name']][$labels][$parsed['bucket'] ?? 0] = $value,
                Keys::SUM_MICROSECONDS => $samples['sum'][$parsed['name']][$labels] = $value,
                default => null,
            };
        }

        $names = array_keys($this->definitions);
        sort($names);
        $out = '';
        foreach ($names as $name) {
            $definition = $this->definitions[$name];
            $out .= '# HELP ' . $name . ' ' . self::escapeHelp($definition['help']) . "\n# TYPE " . $name . ' ' . $definition['type'] . "\n";

            if ('counter' === $definition['type'] || 'gauge' === $definition['type']) {
                $series = $samples[$definition['type']][$name] ?? [];
                ksort($series);
                foreach ($series as $labels => $value) {
                    $out .= $name . self::labels($definition['labels'], $labels) . ' ' . $value . "\n";
                }

                continue;
            }

            $series = $samples['bucket'][$name] ?? [];
            ksort($series);
            foreach ($series as $labels => $perBucket) {
                $cumulative = 0;
                foreach ($definition['buckets'] as $i => $upperBound) {
                    $cumulative += $perBucket[$i] ?? 0;
                    $out .= $name . '_bucket' . self::labels($definition['labels'], $labels, self::number($upperBound)) . ' ' . $cumulative . "\n";
                }
                $count = array_sum($perBucket); // every observation is in exactly one bucket (the last is +Inf): the count needs no counter of its own
                $out .= $name . '_bucket' . self::labels($definition['labels'], $labels, '+Inf') . ' ' . $count . "\n";
                $out .= $name . '_sum' . self::labels($definition['labels'], $labels) . ' ' . self::number(($samples['sum'][$name][$labels] ?? 0) / 1_000_000) . "\n";
                $out .= $name . '_count' . self::labels($definition['labels'], $labels) . ' ' . $count . "\n";
            }
        }

        return $out;
    }

    /**
     * @param list<string> $labelNames
     * @param list<float>  $buckets
     */
    private function define(string $name, string $type, string $help, array $labelNames, array $buckets): void
    {
        if (1 !== preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D', $name)) {
            throw new ConfigurationException(\sprintf('"%s" is not a valid metric name.', $name));
        }
        foreach ($labelNames as $label) {
            if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $label) || str_starts_with($label, '__')) {
                throw new ConfigurationException(\sprintf('"%s" is not a valid label name.', $label));
            }
        }
        if (isset($this->definitions[$name])) {
            throw new ConfigurationException(\sprintf('Metric %s is already declared.', $name));
        }

        $this->definitions[$name] = ['type' => $type, 'help' => $help, 'labels' => $labelNames, 'buckets' => $buckets];
    }

    /**
     * @param list<string> $names
     */
    private static function labels(array $names, string $encodedValues, ?string $le = null): string
    {
        $encoded = substr($encodedValues, 1); // drop the series marker
        $values = '' === $encoded ? [] : array_map(rawurldecode(...), explode(',', $encoded));
        $pairs = [];
        foreach ($names as $i => $label) {
            $pairs[] = $label . '="' . self::escapeLabel($values[$i] ?? '') . '"';
        }
        if (null !== $le) {
            $pairs[] = 'le="' . $le . '"';
        }

        return [] === $pairs ? '' : '{' . implode(',', $pairs) . '}';
    }

    private static function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private static function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $value);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.9F', $value), '0'), '.');
    }
}
