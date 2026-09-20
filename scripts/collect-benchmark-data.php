#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Collects benchmark metrics data from multiple PHP projects.
 * Usage: php scripts/collect-benchmark-data.php [output-file.json] [--capture-dir[=dir]]
 *
 * Projects are sourced from:
 * - benchmarks/vendor/ — open-source projects (installed via benchmarks/composer.json)
 * - vendor/ — projects already available as Qualimetrix dependencies
 * - benchmarks/local-projects.json — private codebases, if configured (never committed)
 *
 * Private codebases are opt-in and machine-local. Copy
 * benchmarks/local-projects.json.example to benchmarks/local-projects.json and
 * point it at your own checkouts; the file is git-ignored so that no private
 * project name or filesystem path can reach the public repository.
 *
 * --capture-dir writes, per project, the full metric map of the project symbol
 * and of every namespace and class symbol to its own file under the given
 * directory (default outside the repository — see $defaultCaptureDir below).
 * This raw capture is the input a formula bench needs and the digest below
 * does not carry; it is never committed.
 */

use Qualimetrix\Subprocess\ChildProcess;

require_once __DIR__ . '/subprocess/ChildProcess.php';

$qmxBin = __DIR__ . '/../bin/qmx';
$benchmarkVendor = __DIR__ . '/../benchmarks/vendor';
$localProjectsFile = __DIR__ . '/../benchmarks/local-projects.json';

// Define benchmark projects
$projects = [
    // Open-source — from benchmarks/vendor (dedicated benchmark deps)
    ['id' => 'symfony-console', 'path' => "$benchmarkVendor/symfony/console", 'type' => 'open-source', 'description' => 'Symfony Console component'],
    ['id' => 'symfony-di', 'path' => "$benchmarkVendor/symfony/dependency-injection", 'type' => 'open-source', 'description' => 'Symfony DI component'],
    ['id' => 'symfony-http-foundation', 'path' => "$benchmarkVendor/symfony/http-foundation", 'type' => 'open-source', 'description' => 'Symfony HttpFoundation component'],
    ['id' => 'symfony-http-kernel', 'path' => "$benchmarkVendor/symfony/http-kernel", 'type' => 'open-source', 'description' => 'Symfony HttpKernel component'],
    ['id' => 'symfony-routing', 'path' => "$benchmarkVendor/symfony/routing", 'type' => 'open-source', 'description' => 'Symfony Routing component'],
    ['id' => 'phpunit', 'path' => "$benchmarkVendor/phpunit/phpunit/src", 'type' => 'open-source', 'description' => 'PHPUnit testing framework'],
    ['id' => 'php-parser', 'path' => "$benchmarkVendor/nikic/php-parser/lib", 'type' => 'open-source', 'description' => 'PHP Parser by nikic'],
    ['id' => 'doctrine-orm', 'path' => "$benchmarkVendor/doctrine/orm/src", 'type' => 'open-source', 'description' => 'Doctrine ORM'],
    ['id' => 'doctrine-dbal', 'path' => "$benchmarkVendor/doctrine/dbal/src", 'type' => 'open-source', 'description' => 'Doctrine DBAL'],
    ['id' => 'flysystem', 'path' => "$benchmarkVendor/league/flysystem/src", 'type' => 'open-source', 'description' => 'Flysystem filesystem abstraction'],
    ['id' => 'composer', 'path' => "$benchmarkVendor/composer/composer/src", 'type' => 'open-source', 'description' => 'Composer package manager'],
    ['id' => 'monolog', 'path' => "$benchmarkVendor/monolog/monolog/src", 'type' => 'open-source', 'description' => 'Monolog logging library'],
    ['id' => 'guzzle', 'path' => "$benchmarkVendor/guzzlehttp/guzzle/src", 'type' => 'open-source', 'description' => 'Guzzle HTTP client'],
    ['id' => 'laravel-framework', 'path' => "$benchmarkVendor/laravel/framework/src", 'type' => 'open-source', 'description' => 'Laravel Framework'],

    // Legacy anchors. The corpus is otherwise fifteen top-decile libraries, so
    // health scores never left the top third of their own scale and the declared
    // Poor and Critical bands were unreachable by any real project. These two are
    // procedural, predate namespaces, and both parse cleanly under PHP 8.4.
    ['id' => 'codeigniter', 'path' => "$benchmarkVendor/codeigniter/framework/system", 'type' => 'open-source', 'description' => 'CodeIgniter 3, procedural legacy'],
    ['id' => 'wordpress', 'path' => "$benchmarkVendor/johnpbloch/wordpress-core/wp-includes", 'type' => 'open-source', 'description' => 'WordPress core includes, procedural legacy'],

    // Qualimetrix itself
    ['id' => 'qmx', 'path' => __DIR__ . '/../src', 'type' => 'open-source', 'description' => 'Qualimetrix'],

];

// Private codebases, if the developer configured any. Machine-local and git-ignored.
if (is_file($localProjectsFile)) {
    $local = json_decode((string) file_get_contents($localProjectsFile), true, 512, JSON_THROW_ON_ERROR);

    foreach ($local['projects'] ?? [] as $project) {
        if (!isset($project['id'], $project['path'])) {
            fwrite(STDERR, "Skipping malformed entry in local-projects.json (needs 'id' and 'path')\n");
            continue;
        }

        $projects[] = [
            'id' => $project['id'],
            'path' => $project['path'],
            'type' => 'private',
            'description' => $project['description'] ?? $project['id'],
        ];
    }
}

// --capture-dir[=dir] is separated from positional arguments so the existing
// `[output-file.json]` usage keeps working unchanged.
$defaultCaptureDir = sys_get_temp_dir() . '/qmx-benchmark-capture';
$captureDir = null;
$positionalArgs = [];
/** @var list<string> $argv */
$cliArgs = array_slice($argv, 1);
foreach ($cliArgs as $arg) {
    if ($arg === '--capture-dir') {
        $captureDir = $defaultCaptureDir;
        continue;
    }
    if (str_starts_with($arg, '--capture-dir=')) {
        $captureDir = substr($arg, strlen('--capture-dir='));
        continue;
    }
    $positionalArgs[] = $arg;
}

if ($captureDir !== null && !is_dir($captureDir) && !mkdir($captureDir, 0o755, true) && !is_dir($captureDir)) {
    fprintf(STDERR, "ERROR: Cannot create capture directory: %s\n", $captureDir);
    exit(2);
}

$outputFile = $positionalArgs[0] ?? __DIR__ . '/../docs/internal/benchmark-data.json';

$qmxVersionOutput = shell_exec("$qmxBin --version 2>/dev/null");
$qmxVersion = is_string($qmxVersionOutput) ? trim($qmxVersionOutput) : '';

// qmx auto-discovers qmx.yaml (and composer.json) from the process working
// directory. Running from the repo root would leak this repository's own
// qmx.yaml — its memory_limit, its Qualimetrix\** architecture layers, and
// its coupling framework namespaces — onto every benchmark project, so every
// project would be measured under a configuration that is not its own. A
// fresh, empty working directory turns auto-discovery into a no-op, while the
// absolute $qmxBin and per-project $path keep each invocation self-contained.
$neutralDir = sys_get_temp_dir() . '/qmx-benchmark-collect-' . getmypid();
if (!is_dir($neutralDir) && !mkdir($neutralDir, 0o755, true) && !is_dir($neutralDir)) {
    fprintf(STDERR, "ERROR: Cannot create neutral working directory: %s\n", $neutralDir);
    exit(2);
}

$results = [
    'version' => '1.0',
    'collected_at' => date('c'),
    'qmx_version' => $qmxVersion !== '' ? $qmxVersion : 'unknown',
    'projects' => [],
];
$failures = [];

foreach ($projects as $project) {
    // Free the previous iteration's decoded payload before allocating this
    // one's: $data holds a fresh value only once assignment completes, so
    // without this, the largest project's ~12.5 MB decoded array stays alive
    // while the next project's json_decode() builds its own copy — the
    // combination is what exhausted the default 128M limit.
    unset($data, $json, $output, $namespaces, $classes, $captureSymbols, $encodedCapture, $projectResult);

    $path = $project['path'];
    $id = $project['id'];

    if (!is_dir($path)) {
        fprintf(STDERR, "SKIP: %s (path not found: %s)\n", $id, $path);
        $failures[] = sprintf('%s: benchmark path not found', $id);
        continue;
    }

    fprintf(STDERR, "Analyzing: %s ... ", $id);
    $start = microtime(true);

    // An argument-vector command needs no shell and therefore no
    // escapeshellarg(): each element reaches the child exactly as written.
    $cmd = [
        'php',
        '-d',
        'memory_limit=2G',
        $qmxBin,
        'check',
        $path,
        '--format=metrics',
        '--workers=0',
    ];

    // Run from the neutral working directory (see $neutralDir above) so qmx
    // does not auto-discover this repository's qmx.yaml/composer.json.
    // ChildProcess::run() also avoids exec()'s double memory ownership of
    // large output (a line array plus its imploded string) — the qmx
    // self-analysis alone produces ~12.5 MB of JSON.
    try {
        $result = ChildProcess::run($cmd, $neutralDir);
    } catch (RuntimeException $exception) {
        fprintf(STDERR, "FAILED (could not run analysis): %s\n", $exception->getMessage());
        $failures[] = sprintf('%s: could not run analysis (%s)', $id, $exception->getMessage());
        continue;
    }
    $json = $result['stdout'];
    $exitCode = $result['exitCode'];
    $elapsed = round(microtime(true) - $start, 1);

    if ($exitCode > 2) {
        fprintf(STDERR, "FAILED (analysis exit code %d)\n", $exitCode);
        $message = sprintf('%s: analysis exited with code %d', $id, $exitCode);
        $stderr = trim($result['stderr']);
        if ($stderr !== '') {
            fprintf(STDERR, "  stderr: %s\n", $stderr);
        }
        $failures[] = $message;
        continue;
    }

    if ($json === '') {
        fprintf(STDERR, "FAILED (no output)\n");
        $failures[] = sprintf('%s: analysis produced no output', $id);
        continue;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['symbols']) || !is_array($data['symbols'])) {
        fprintf(STDERR, "FAILED (invalid JSON)\n");
        $failures[] = sprintf('%s: invalid JSON output', $id);
        continue;
    }

    if (($data['coverage']['complete'] ?? null) !== true) {
        fprintf(STDERR, "FAILED (analysis coverage incomplete or missing)\n");
        $failures[] = sprintf('%s: analysis coverage is not complete', $id);
        continue;
    }

    // Raw capture: the full metric map of the project, namespace and class
    // symbols, for a formula bench — a different question from the digest
    // built below, so it is written to its own file rather than merged in.
    if ($captureDir !== null) {
        $captureSymbols = [];
        foreach ($data['symbols'] as $symbol) {
            if (!in_array($symbol['type'], ['project', 'namespace', 'class'], true)) {
                continue;
            }
            $captureSymbols[] = [
                'name' => $symbol['name'],
                'type' => $symbol['type'],
                'metrics' => $symbol['metrics'] ?? [],
                'size.loc' => $symbol['metrics']['size.loc'] ?? null,
            ];
        }

        // No JSON_PRETTY_PRINT: this file is machine-read only, and pretty-printing
        // a multi-megabyte payload roughly doubles its peak memory for no benefit.
        $captureFile = $captureDir . '/' . $id . '.json';
        $encodedCapture = json_encode(
            ['id' => $id, 'symbols' => $captureSymbols],
            JSON_UNESCAPED_UNICODE,
        );
        if ($encodedCapture === false || file_put_contents($captureFile, $encodedCapture) === false) {
            fprintf(STDERR, "FAILED (could not write capture file)\n");
            $failures[] = sprintf('%s: could not write capture file', $id);
            continue;
        }
    }

    // Extract namespace-level metrics
    $namespaces = [];
    $classes = [];
    foreach ($data['symbols'] as $symbol) {
        if ($symbol['type'] === 'namespace') {
            $ns = [
                'name' => $symbol['name'],
                'metrics' => [],
            ];
            // Extract relevant metrics
            $keys = [
                'coupling.cbo.avg', 'coupling.cbo.max', 'coupling.cbo.sum', 'coupling.cbo.count',
                'coupling.distance', 'coupling.instability', 'coupling.abstractness',
                'coupling.ca', 'coupling.ce',
                'health.coupling', 'health.complexity', 'health.cohesion',
                'health.typing', 'health.maintainability', 'health.overall',
                'complexity.ccn.avg', 'complexity.ccn.max', 'complexity.cognitive.avg', 'complexity.cognitive.max',
                'cohesion.tcc.avg', 'cohesion.lcom.avg', 'cohesion.lcom.max',
                'size.loc.sum', 'size.class-count.sum',
                'size.abstract-class-count.sum', 'size.interface-count.sum', 'size.enum-count.sum',
                'maintainability.mi.avg',
            ];
            foreach ($keys as $key) {
                if (isset($symbol['metrics'][$key])) {
                    $ns['metrics'][$key] = $symbol['metrics'][$key];
                }
            }
            $namespaces[] = $ns;
        } elseif ($symbol['type'] === 'class') {
            $classes[] = [
                'name' => $symbol['name'],
                'coupling.cbo' => $symbol['metrics']['coupling.cbo'] ?? null,
                'health.coupling' => $symbol['metrics']['health.coupling'] ?? null,
                'size.loc' => $symbol['metrics']['size.loc'] ?? null,
            ];
        }
    }

    // Compute distributions
    $distributions = [];
    $nsMetricKeys = ['health.coupling', 'health.complexity', 'health.cohesion',
        'health.typing', 'health.maintainability', 'health.overall',
        'coupling.cbo.avg', 'coupling.distance', 'complexity.ccn.avg', 'complexity.cognitive.avg', 'cohesion.tcc.avg', 'cohesion.lcom.avg'];

    foreach ($nsMetricKeys as $key) {
        $values = array_filter(array_map(
            fn($ns) => $ns['metrics'][$key] ?? null,
            $namespaces,
        ), fn($v) => $v !== null);
        sort($values);
        $distributions[$key] = percentiles($values);
    }

    // Class-level CBO distribution
    $classCboValues = array_filter(array_map(
        fn($c) => $c['coupling.cbo'],
        $classes,
    ), fn($v) => $v !== null);
    sort($classCboValues);
    $distributions['class_cbo'] = percentiles($classCboValues);

    $projectResult = [
        'id' => $id,
        'description' => $project['description'],
        'type' => $project['type'],
        'path' => $path,
        'analysis_time_s' => $elapsed,
        'counts' => [
            'namespaces' => count($namespaces),
            'classes' => count($classes),
        ],
        'distributions' => $distributions,
        'namespaces' => $namespaces,
        'outliers' => [
            'health_coupling_zero' => array_values(array_filter(
                $namespaces,
                fn($ns) => ($ns['metrics']['health.coupling'] ?? 100) === 0,
            )),
            'cbo_avg_gt_20' => array_values(array_filter(
                $namespaces,
                fn($ns) => ($ns['metrics']['coupling.cbo.avg'] ?? 0) > 20,
            )),
            'class_cbo_gt_30' => array_values(array_filter(
                $classes,
                fn($c) => ($c['coupling.cbo'] ?? 0) > 30,
            )),
        ],
    ];

    $results['projects'][] = $projectResult;
    fprintf(
        STDERR,
        "OK (%ds, %d ns, %d classes, peak %s)\n",
        $elapsed,
        count($namespaces),
        count($classes),
        round(memory_get_peak_usage(true) / 1_048_576, 1) . 'M',
    );
}

if ($failures !== [] || count($results['projects']) !== count($projects)) {
    fprintf(STDERR, "\nCollection aborted: %d benchmark project(s) were skipped or failed.\n", count($failures));
    foreach ($failures as $failure) {
        fprintf(STDERR, "  - %s\n", $failure);
    }
    exit(1);
}

// Write output only after every configured project produced an authoritative artifact.
$encodedResults = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($encodedResults === false || file_put_contents($outputFile, $encodedResults) === false) {
    fprintf(STDERR, "ERROR: Cannot write output file: %s\n", $outputFile);
    exit(2);
}
fprintf(STDERR, "\nOutput written to: %s\n", $outputFile);

// Print summary table
fprintf(STDERR, "\n=== HEALTH COUPLING DISTRIBUTION ===\n");
fprintf(
    STDERR,
    "%-25s %4s %4s  %4s %4s %4s %4s %4s  %5s\n",
    'Project',
    'NS',
    'CLS',
    'P10',
    'P25',
    'P50',
    'P75',
    'P90',
    'zeros',
);
fprintf(STDERR, "%s\n", str_repeat('-', 85));
foreach ($results['projects'] as $p) {
    $hc = $p['distributions']['health.coupling'] ?? null;

    if ($hc === null) {
        continue;
    }
    fprintf(
        STDERR,
        "%-25s %4d %4d  %4.0f %4.0f %4.0f %4.0f %4.0f  %5d\n",
        $p['id'],
        $p['counts']['namespaces'],
        $p['counts']['classes'],
        $hc['p10'] ?? 0,
        $hc['p25'] ?? 0,
        $hc['p50'] ?? 0,
        $hc['p75'] ?? 0,
        $hc['p90'] ?? 0,
        count($p['outliers']['health_coupling_zero']),
    );
}

// Print all-projects aggregate
$allHC = [];
foreach ($results['projects'] as $p) {
    foreach ($p['namespaces'] as $ns) {
        if (isset($ns['metrics']['health.coupling'])) {
            $allHC[] = $ns['metrics']['health.coupling'];
        }
    }
}
sort($allHC);
$agg = percentiles($allHC);
fprintf(STDERR, "%s\n", str_repeat('-', 85));
fprintf(
    STDERR,
    "%-25s %4d %4s  %4.0f %4.0f %4.0f %4.0f %4.0f  %5s\n",
    'ALL PROJECTS',
    $agg['count'],
    '',
    $agg['p10'],
    $agg['p25'],
    $agg['p50'],
    $agg['p75'],
    $agg['p90'],
    '',
);

/**
 * @param list<float|int> $sorted
 *
 * @return array<string, float|int>
 */
function percentiles(array $sorted): array
{
    $n = count($sorted);
    if ($n === 0) {
        return ['count' => 0];
    }

    return [
        'count' => $n,
        'min' => $sorted[0],
        'p5' => $sorted[(int) ($n * 0.05)],
        'p10' => $sorted[(int) ($n * 0.10)],
        'p25' => $sorted[(int) ($n * 0.25)],
        'p50' => $sorted[(int) ($n * 0.50)],
        'p75' => $sorted[(int) ($n * 0.75)],
        'p90' => $sorted[(int) ($n * 0.90)],
        'p95' => $sorted[(int) ($n * 0.95)],
        'max' => $sorted[$n - 1],
        'mean' => round(array_sum($sorted) / $n, 2),
    ];
}
