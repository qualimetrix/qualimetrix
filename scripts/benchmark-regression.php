#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Benchmark regression checker for health score formulas.
 *
 * Runs Qualimetrix analysis on benchmark projects and compares project-level health scores
 * against expected ranges defined in docs/internal/benchmark-baselines.json.
 *
 * Usage: php scripts/benchmark-regression.php [--update-baselines]
 *
 * Exit codes:
 *   0 — all scores within expected ranges
 *   1 — regression detected (scores outside ranges)
 *   2 — infrastructure error (missing deps, invalid baseline, etc.)
 */

$rootDir = dirname(__DIR__);
$qmxBin = $rootDir . '/bin/qmx';
$baselineFile = $rootDir . '/docs/internal/benchmark-baselines.json';

// Parse arguments
/** @var list<string> $argv */
$updateBaselines = in_array('--update-baselines', $argv, true);

// Load baselines
if (!file_exists($baselineFile)) {
    fprintf(STDERR, "ERROR: Baseline file not found: %s\n", $baselineFile);
    exit(2);
}

$baselineContent = file_get_contents($baselineFile);
if ($baselineContent === false) {
    fprintf(STDERR, "ERROR: Cannot read baseline file: %s\n", $baselineFile);
    exit(2);
}

$baselines = json_decode($baselineContent, true);
if (!is_array($baselines) || !isset($baselines['projects']) || !is_array($baselines['projects'])) {
    fprintf(STDERR, "ERROR: Invalid baseline file format\n");
    exit(2);
}

$projects = $baselines['projects'];
$failures = [];
$results = [];

fprintf(STDERR, "Benchmark regression check (%d projects)\n", count($projects));
fprintf(STDERR, "%s\n", str_repeat('=', 80));

foreach ($projects as $id => $config) {
    $path = $rootDir . '/' . $config['path'];

    if (!is_dir($path)) {
        fprintf(STDERR, "SKIP: %s (path not found: %s)\n", $id, $config['path']);
        $failures[] = sprintf('%s: benchmark path not found', $id);

        continue;
    }

    fprintf(STDERR, "  %-25s ", $id);
    $start = microtime(true);

    // Build command with optional disable-rules
    $cmd = sprintf(
        'php -d memory_limit=2G %s check %s --format=metrics --workers=0',
        escapeshellarg($qmxBin),
        escapeshellarg($path),
    );

    if (isset($config['disable_rules']) && $config['disable_rules'] !== []) {
        foreach ($config['disable_rules'] as $rule) {
            $cmd .= ' --disable-rule=' . escapeshellarg($rule);
        }
    }

    $cmd .= ' 2>/dev/null';

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $json = implode("\n", $output);
    $elapsed = round(microtime(true) - $start, 1);

    if ($exitCode > 2) {
        fprintf(STDERR, "FAILED (analysis exit code %d, %.1fs)\n", $exitCode, $elapsed);
        $failures[] = sprintf('%s: analysis exited with code %d', $id, $exitCode);

        continue;
    }

    if ($json === '') {
        fprintf(STDERR, "FAILED (no output, %.1fs)\n", $elapsed);
        $failures[] = sprintf('%s: analysis produced no output', $id);

        continue;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['symbols']) || !is_array($data['symbols'])) {
        fprintf(STDERR, "FAILED (invalid JSON, %.1fs)\n", $elapsed);
        $failures[] = sprintf('%s: invalid JSON output', $id);

        continue;
    }

    if (($data['coverage']['complete'] ?? null) !== true) {
        fprintf(STDERR, "FAILED (analysis coverage incomplete or missing, %.1fs)\n", $elapsed);
        $failures[] = sprintf('%s: analysis coverage is not complete', $id);

        continue;
    }

    // Find project-level symbol
    $projectMetrics = null;
    foreach ($data['symbols'] as $symbol) {
        if ($symbol['type'] === 'project') {
            $projectMetrics = $symbol['metrics'];

            break;
        }
    }

    if ($projectMetrics === null) {
        fprintf(STDERR, "FAILED (no project symbol, %.1fs)\n", $elapsed);
        $failures[] = sprintf('%s: no project-level symbol in output', $id);

        continue;
    }

    // Check expectations
    $projectFailures = [];
    $scores = [];
    foreach ($config['expectations'] as $metric => [$min, $max]) {
        $value = $projectMetrics[$metric] ?? null;
        if ($value === null) {
            $projectFailures[] = sprintf('%s: metric %s not found', $id, $metric);

            continue;
        }

        $rounded = round($value, 1);
        $scores[$metric] = $rounded;

        if ($rounded < $min || $rounded > $max) {
            $projectFailures[] = sprintf(
                '%s: %s = %.1f, expected [%d, %d]',
                $id,
                $metric,
                $rounded,
                $min,
                $max,
            );
        }
    }

    $results[$id] = $scores;

    if (count($projectFailures) > 0) {
        fprintf(STDERR, "FAIL (%.1fs)\n", $elapsed);
        $failures = array_merge($failures, $projectFailures);
    } else {
        fprintf(STDERR, "OK   (%.1fs)\n", $elapsed);
    }
}

// Print summary
fprintf(STDERR, "\n%s\n", str_repeat('=', 80));

if (count($results) > 0) {
    fprintf(
        STDERR,
        "\n%-25s %6s %6s %6s %6s %6s\n",
        'Project',
        'cmplx',
        'cohsn',
        'cplng',
        'maint',
        'ovral',
    );
    fprintf(STDERR, "%s\n", str_repeat('-', 67));

    foreach ($results as $id => $scores) {
        fprintf(
            STDERR,
            "%-25s %6.1f %6.1f %6.1f %6.1f %6.1f\n",
            $id,
            $scores['health.complexity'] ?? 0,
            $scores['health.cohesion'] ?? 0,
            $scores['health.coupling'] ?? 0,
            $scores['health.maintainability'] ?? 0,
            $scores['health.overall'] ?? 0,
        );
    }
}

// Baseline replacement is atomic at the project-set level: a partial corpus
// must never ratchet only the projects that happened to finish.
if ($updateBaselines && $failures === [] && count($results) === count($projects)) {
    fprintf(STDERR, "\nUpdating baselines...\n");
    $margin = 10;

    foreach ($results as $id => $scores) {
        foreach ($scores as $metric => $value) {
            $baselines['projects'][$id]['expectations'][$metric] = [
                max(0, (int) floor($value - $margin)),
                min(100, (int) ceil($value + $margin)),
            ];
        }
    }

    $baselines['updated_at'] = date('Y-m-d');
    $encodedBaselines = json_encode($baselines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encodedBaselines === false || file_put_contents($baselineFile, $encodedBaselines . "\n") === false) {
        fprintf(STDERR, "ERROR: Cannot write baseline file: %s\n", $baselineFile);
        exit(2);
    }
    fprintf(STDERR, "Baselines updated in: %s\n", $baselineFile);
    exit(0);
}

if (count($failures) > 0) {
    fprintf(STDERR, "\nREGRESSION DETECTED (%d failures):\n", count($failures));

    foreach ($failures as $failure) {
        fprintf(STDERR, "  - %s\n", $failure);
    }

    exit(1);
}

fprintf(STDERR, "\nAll %d projects within expected ranges.\n", count($results));
exit(0);
