#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Collects benchmark metrics data from multiple PHP projects.
 * Usage: php scripts/collect-benchmark-data.php [output-file.json]
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
 */

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

$outputFile = $argv[1] ?? __DIR__ . '/../docs/internal/benchmark-data.json';

$qmxVersionOutput = shell_exec("$qmxBin --version 2>/dev/null");
$qmxVersion = is_string($qmxVersionOutput) ? trim($qmxVersionOutput) : '';

$results = [
    'version' => '1.0',
    'collected_at' => date('c'),
    'qmx_version' => $qmxVersion !== '' ? $qmxVersion : 'unknown',
    'projects' => [],
];
$failures = [];

foreach ($projects as $project) {
    $path = $project['path'];
    $id = $project['id'];

    if (!is_dir($path)) {
        fprintf(STDERR, "SKIP: %s (path not found: %s)\n", $id, $path);
        $failures[] = sprintf('%s: benchmark path not found', $id);
        continue;
    }

    fprintf(STDERR, "Analyzing: %s ... ", $id);
    $start = microtime(true);

    $cmd = sprintf(
        'php -d memory_limit=2G %s check %s --format=metrics --workers=0 2>/dev/null',
        escapeshellarg($qmxBin),
        escapeshellarg($path),
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $json = implode("\n", $output);
    $elapsed = round(microtime(true) - $start, 1);

    if ($exitCode > 2) {
        fprintf(STDERR, "FAILED (analysis exit code %d)\n", $exitCode);
        $failures[] = sprintf('%s: analysis exited with code %d', $id, $exitCode);
        continue;
    }

    if (trim($json) === '') {
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
    fprintf(STDERR, "OK (%ds, %d ns, %d classes)\n", $elapsed, count($namespaces), count($classes));
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
