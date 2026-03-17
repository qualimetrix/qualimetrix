#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Benchmark regression checker for health score formulas.
 *
 * Runs AIMD analysis on benchmark projects and compares project-level health scores
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
$aimdBin = $rootDir . '/bin/aimd';
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
if (!$baselines || !isset($baselines['projects'])) {
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

        continue;
    }

    fprintf(STDERR, "  %-25s ", $id);
    $start = microtime(true);

    // Build command with optional disable-rules
    $cmd = sprintf(
        'php -d memory_limit=2G %s check %s --format=metrics-json --workers=0',
        escapeshellarg($aimdBin),
        escapeshellarg($path),
    );

    if (!empty($config['disable_rules'])) {
        foreach ($config['disable_rules'] as $rule) {
            $cmd .= ' --disable-rule=' . escapeshellarg($rule);
        }
    }

    $cmd .= ' 2>/dev/null';

    $json = shell_exec($cmd);
    $elapsed = round(microtime(true) - $start, 1);

    if (!$json) {
        fprintf(STDERR, "FAILED (no output, %.1fs)\n", $elapsed);
        $failures[] = sprintf('%s: analysis produced no output', $id);

        continue;
    }

    $data = json_decode($json, true);
    if (!$data || !isset($data['symbols'])) {
        fprintf(STDERR, "FAILED (invalid JSON, %.1fs)\n", $elapsed);
        $failures[] = sprintf('%s: invalid JSON output', $id);

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

// Update baselines if requested (runs regardless of pass/fail)
if ($updateBaselines && count($results) > 0) {
    $totalProjects = count($projects);
    $analyzedProjects = count($results);

    if ($analyzedProjects < $totalProjects) {
        fprintf(
            STDERR,
            "\nWARNING: updating baselines with only %d/%d projects analyzed. "
            . "Missing projects retain old baselines.\n",
            $analyzedProjects,
            $totalProjects,
        );
    }

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
    file_put_contents($baselineFile, json_encode($baselines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
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
