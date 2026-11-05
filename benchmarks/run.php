<?php

declare(strict_types=1);

use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Auth\Jwt\StaticKeyProvider;
use Slimfony\Core\Benchmarks\BenchAuthenticator;
use Slimfony\Core\Benchmarks\LatencyBenchmark;
use Slimfony\Core\Benchmarks\LatencyResult;
use Slimfony\Core\Kernel;
use Slimfony\Core\Observability\ApcuStorage;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Tests\Support\JwtKeys;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/../vendor/autoload.php';

/*
 * Per-request latency of the core (Kernel::handle with the production middleware chain), as percentiles.
 *
 *   composer bench                          measure and print a table
 *   composer bench -- --iterations=50000    more samples
 *   composer bench -- --max-p95-us=250      exit 1 when any scenario's p95 is above the limit (a regression guard)
 *
 * The JWT scenarios verify a real signature on every request (RS256, ES256, EdDSA), so they show what authentication costs.
 */

$options = getopt('', ['iterations::', 'max-p95-us::']);
$iterations = isset($options['iterations']) && is_string($options['iterations']) ? (int) $options['iterations'] : 30000;
$maxP95 = isset($options['max-p95-us']) && is_string($options['max-p95-us']) ? (float) $options['max-p95-us'] : null;

if (!extension_loaded('apcu') || !filter_var(ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN) || !filter_var(ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOLEAN)) {
    fwrite(\STDERR, "The benchmark needs APCu (it is part of the production chain): run with -d apc.enable_cli=1.\n");
    exit(2);
}

$kernelWith = static fn(Authenticator $authenticator): Kernel => new Kernel(
    __DIR__ . '/services.php',
    __DIR__ . '/routes.php',
    synthetic: [
        MetricsRegistry::class => new MetricsRegistry(new ApcuStorage('slimfony_bench:')),
        Authenticator::class => $authenticator,
    ],
);

$bench = new LatencyBenchmark($kernelWith(new BenchAuthenticator()), iterations: $iterations);
$authorization = ['HTTP_AUTHORIZATION' => 'Bearer bench'];
$results = [
    $bench->measure('GET /healthz (public route)', static fn(): Request => Request::create('/healthz'), 200),
    $bench->measure('GET /hello/{name} (authenticated, scope check, JSON)', static fn(): Request => Request::create('/hello/world', server: $authorization), 200),
    $bench->measure('GET /unknown (404 through the chain)', static fn(): Request => Request::create('/unknown', server: $authorization), 404),
];

foreach (['RS256', 'ES256', 'EdDSA'] as $algorithm) {
    $keys = JwtKeys::generate($algorithm);
    $token = $keys->token(['iss' => 'https://idp.bench', 'aud' => 'bench-api', 'sub' => 'bench', 'exp' => time() + 3600, 'scope' => 'bench:read']);
    $provider = new StaticKeyProvider([$keys->keyId => $keys->verificationKey]);
    $configuration = new JwtConfiguration('https://idp.bench', 'bench-api', [$algorithm]);

    // 30 s: the default, a token that already verified is remembered; 0: every request verifies the signature
    foreach ([30 => 'token remembered, default', 0 => 'signature verified on every request'] as $cacheSeconds => $label) {
        $results[] = new LatencyBenchmark($kernelWith(new JwtAuthenticator($configuration, $provider, cacheSeconds: $cacheSeconds)), iterations: $iterations)->measure(
            sprintf('GET /hello/{name} (%s JWT, %s)', $algorithm, $label),
            static fn(): Request => Request::create('/hello/world', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]),
            200,
        );
    }
}
apcu_clear_cache();

$opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
$opcacheOn = is_array($opcache) && true === ($opcache['opcache_enabled'] ?? false);
$jitStatus = is_array($opcache) ? ($opcache['jit'] ?? null) : null;
$jitOn = is_array($jitStatus) && true === ($jitStatus['on'] ?? false);
$report = sprintf(
    "PHP %s (%s), opcache %s, JIT %s, APCu %s, %s\n\n",
    \PHP_VERSION,
    ZEND_THREAD_SAFE ? 'ZTS' : 'NTS',
    $opcacheOn ? 'on' : 'OFF (numbers are pessimistic)',
    $jitOn ? 'on' : 'off',
    phpversion('apcu'),
    php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
) . "| Scenario | Requests | p50 µs | p95 µs | p99 µs | max µs |\n|---|---:|---:|---:|---:|---:|\n"
    . implode("\n", array_map(static fn(LatencyResult $r): string => $r->markdownRow(), $results)) . "\n";

echo $report;
$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && '' !== $summary) {
    file_put_contents($summary, "### Core latency (Kernel::handle, in-process)\n\n" . $report, \FILE_APPEND);
}

if (null !== $maxP95) {
    foreach ($results as $result) {
        if ($result->p95 / 1000 > $maxP95) {
            fwrite(\STDERR, sprintf("p95 of \"%s\" is %.1f µs, above the limit of %.1f µs.\n", $result->scenario, $result->p95 / 1000, $maxP95));
            exit(1);
        }
    }
}
