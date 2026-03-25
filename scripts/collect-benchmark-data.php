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
 * - ~/PhpstormProjects/ — proprietary projects (local only)
 */

$qmxBin = __DIR__ . '/../bin/qmx';
$phpstormDir = dirname(__DIR__, 2);
$benchmarkVendor = __DIR__ . '/../benchmarks/vendor';

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

    // Proprietary — large
    ['id' => 'newlk', 'path' => "$phpstormDir/newlk/src", 'type' => 'proprietary', 'description' => 'Large proprietary app (~3100 files)'],
    ['id' => 'bank-lkz-atb', 'path' => "$phpstormDir/bank-lkz-atb/src", 'type' => 'proprietary', 'description' => 'Banking app (~2200 files)'],
    ['id' => 'comm-platform', 'path' => "$phpstormDir/comm_platform/src", 'type' => 'proprietary', 'description' => 'Communication platform (~1800 files)'],

    // Proprietary — medium
    ['id' => 'async-bank-bus', 'path' => "$phpstormDir/async-bank-bus/src", 'type' => 'proprietary', 'description' => 'Async banking bus (~1000 files)'],
    ['id' => 'booking', 'path' => "$phpstormDir/booking/src", 'type' => 'proprietary', 'description' => 'Booking service (~900 files)'],
    ['id' => 'event', 'path' => "$phpstormDir/event/src", 'type' => 'proprietary', 'description' => 'Event service (~600 files)'],
    ['id' => 'bank-lkz', 'path' => "$phpstormDir/bank-lkz/src", 'type' => 'proprietary', 'description' => 'Banking LKZ (~600 files)'],

    // Proprietary — small
    ['id' => 'event-streaming', 'path' => "$phpstormDir/event-streaming/src", 'type' => 'proprietary', 'description' => 'Event streaming (~250 files)'],
    ['id' => 'documenter', 'path' => "$phpstormDir/documenter/src", 'type' => 'proprietary', 'description' => 'Documentation tool (~120 files)'],
    ['id' => 'batch', 'path' => "$phpstormDir/batch/src", 'type' => 'proprietary', 'description' => 'Batch processing (~115 files)'],
    ['id' => 's3-file-proxy', 'path' => "$phpstormDir/s3-file-proxy/src", 'type' => 'proprietary', 'description' => 'S3 file proxy (~40 files)'],
];

$outputFile = $argv[1] ?? __DIR__ . '/../docs/internal/benchmark-data.json';

$results = [
    'version' => '1.0',
    'collected_at' => date('c'),
    'qmx_version' => trim(shell_exec("$qmxBin --version 2>/dev/null") ?: 'unknown'),
    'projects' => [],
];

foreach ($projects as $project) {
    $path = $project['path'];
    $id = $project['id'];

    if (!is_dir($path)) {
        fprintf(STDERR, "SKIP: %s (path not found: %s)\n", $id, $path);
        continue;
    }

    fprintf(STDERR, "Analyzing: %s ... ", $id);
    $start = microtime(true);

    $cmd = sprintf(
        'php -d memory_limit=2G %s check %s --format=metrics --workers=0 2>/dev/null',
        escapeshellarg($qmxBin),
        escapeshellarg($path),
    );

    $json = shell_exec($cmd);
    $elapsed = round(microtime(true) - $start, 1);

    if (!$json) {
        fprintf(STDERR, "FAILED (no output)\n");
        continue;
    }

    $data = json_decode($json, true);
    if (!$data || !isset($data['symbols'])) {
        fprintf(STDERR, "FAILED (invalid JSON)\n");
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
                'cbo.avg', 'cbo.max', 'cbo.sum', 'cbo.count',
                'distance', 'instability', 'abstractness',
                'ca', 'ce',
                'health.coupling', 'health.complexity', 'health.cohesion',
                'health.typing', 'health.maintainability', 'health.overall',
                'ccn.avg', 'ccn.max', 'cognitive.avg', 'cognitive.max',
                'tcc.avg', 'lcom.avg', 'lcom.max',
                'loc.sum', 'classCount.sum',
                'abstractClassCount.sum', 'interfaceCount.sum', 'enumCount.sum',
                'mi.avg',
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
                'cbo' => $symbol['metrics']['cbo'] ?? null,
                'health.coupling' => $symbol['metrics']['health.coupling'] ?? null,
                'loc' => $symbol['metrics']['loc'] ?? null,
            ];
        }
    }

    // Compute distributions
    $distributions = [];
    $nsMetricKeys = ['health.coupling', 'health.complexity', 'health.cohesion',
        'health.typing', 'health.maintainability', 'health.overall',
        'cbo.avg', 'distance', 'ccn.avg', 'cognitive.avg', 'tcc.avg', 'lcom.avg'];

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
        fn($c) => $c['cbo'],
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
                fn($ns) => ($ns['metrics']['cbo.avg'] ?? 0) > 20,
            )),
            'class_cbo_gt_30' => array_values(array_filter(
                $classes,
                fn($c) => ($c['cbo'] ?? 0) > 30,
            )),
        ],
    ];

    $results['projects'][] = $projectResult;
    fprintf(STDERR, "OK (%ds, %d ns, %d classes)\n", $elapsed, count($namespaces), count($classes));
}

// Write output
file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
    $hc = $p['distributions']['health.coupling'];
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
