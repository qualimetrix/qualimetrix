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
 *   0 — all scores within expected ranges (or --update-baselines wrote successfully and
 *       every expected metric was measured)
 *   1 — regression detected (an expectation mismatch, or an expected metric that was
 *       not measured — see below). Both apply under --update-baselines too: a write
 *       that left a stale expectation standing exits 1, or the operator commits a
 *       baseline the next `benchmark:check` reddens and no further update can clean.
 *   2 — infrastructure error (missing deps, invalid baseline, a benchmark path not
 *       found, an analysis that failed to run or produced unreadable output, etc.)
 *
 * `--update-baselines` distinguishes three outcomes per project, not two:
 *   - an infrastructure failure (the project could not be analysed at all) always
 *     blocks the write, together with a partial corpus (some configured project did
 *     not produce a result);
 *   - an expectation mismatch (a measured value outside its recorded range) does NOT
 *     block the write — recalibration exists precisely to correct these;
 *   - a metric the baseline expects but the analysis did not measure is neither of the
 *     above. It cannot be re-seeded (there is no value to write), so the write leaves
 *     that one expectation untouched rather than deleting it, and it is reported
 *     distinctly from both other cases — on the project's own line, in its own block
 *     after the write's confirmation, and in the exit code.
 * A project entry carrying only `path` (no `expectations` block) is accepted: every
 * canonical health metric is measured and, if `--update-baselines` is given, seeded
 * from scratch.
 */

use Qualimetrix\Subprocess\ChildProcess;

require_once __DIR__ . '/subprocess/ChildProcess.php';

/** @var list<string> HEALTH_METRICS */
const HEALTH_METRICS = [
    'health.complexity',
    'health.cohesion',
    'health.coupling',
    'health.maintainability',
    'health.typing',
    'health.overall',
];

/**
 * Linear-interpolated percentile (the common "type 7" definition) over an
 * already-sorted, non-empty list.
 *
 * @param non-empty-list<float> $sortedValues
 */
function percentileOf(array $sortedValues, float $p): float
{
    $count = count($sortedValues);
    if ($count === 1) {
        return $sortedValues[0];
    }

    $rank = $p * ($count - 1);
    $lowerIndex = (int) floor($rank);
    $upperIndex = (int) ceil($rank);
    if ($lowerIndex === $upperIndex) {
        return $sortedValues[$lowerIndex];
    }

    $fraction = $rank - $lowerIndex;

    return $sortedValues[$lowerIndex] + ($sortedValues[$upperIndex] - $sortedValues[$lowerIndex]) * $fraction;
}

/**
 * A missing score and a zero score are different claims about the world, and the summary
 * table must not let one stand in for the other: `n/a` right-padded to the same width as
 * a `%6.1f` cell, rather than a substituted `0`.
 *
 * @param array<string, float> $scores
 */
function formatScoreCell(array $scores, string $metric): string
{
    if (!array_key_exists($metric, $scores)) {
        return str_pad('n/a', 6, ' ', STR_PAD_LEFT);
    }

    return sprintf('%6.1f', $scores[$metric]);
}

/**
 * Median and interquartile range of every health.* dimension across every
 * symbol of the given level ('namespace' or 'class') in one analysis run.
 * A dimension with no measured symbols at that level is recorded as null
 * rather than omitted, so a caller can tell "not applicable here" from
 * "forgot to ask".
 *
 * @param list<array{type: string, name: string, metrics: array<string, mixed>}> $symbols
 *
 * @return array<string, array{count: int, median: float, p25: float, p75: float, iqr: float}|null>
 */
function levelDistribution(array $symbols, string $level): array
{
    /** @var array<string, list<float>> $byMetric */
    $byMetric = array_fill_keys(HEALTH_METRICS, []);

    foreach ($symbols as $symbol) {
        if (($symbol['type'] ?? null) !== $level) {
            continue;
        }

        foreach (HEALTH_METRICS as $metric) {
            $value = $symbol['metrics'][$metric] ?? null;
            if (is_int($value) || is_float($value)) {
                $byMetric[$metric][] = (float) $value;
            }
        }
    }

    $distribution = [];
    foreach ($byMetric as $metric => $values) {
        if ($values === []) {
            $distribution[$metric] = null;

            continue;
        }

        sort($values);
        $p25 = percentileOf($values, 0.25);
        $p75 = percentileOf($values, 0.75);
        $distribution[$metric] = [
            'count' => count($values),
            'median' => round(percentileOf($values, 0.5), 2),
            'p25' => round($p25, 2),
            'p75' => round($p75, 2),
            'iqr' => round($p75 - $p25, 2),
        ];
    }

    return $distribution;
}

$rootDir = dirname(__DIR__);
$qmxBin = $rootDir . '/bin/qmx';
$baselineFile = $rootDir . '/docs/internal/benchmark-baselines.json';
$distributionFile = $rootDir . '/docs/internal/benchmark-namespace-class-distribution.json';

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

// $failures drives the exit code (1 if anything is wrong at all); $infrastructureFailures is
// the narrower list that blocks --update-baselines from writing (see the docblock above).
$failures = [];
$infrastructureFailures = [];
// The list that outlives a successful write: an expectation the analysis did not
// produce cannot be re-seeded, so the write leaves it standing — and a run that leaves
// a stale expectation standing has to say so in --update-baselines as loudly as in a check.
$unmeasuredAcrossRun = [];
$results = [];
$distributions = [];

// qmx auto-discovers qmx.yaml (and composer.json) from the process working
// directory. Running from the repo root would leak the repo's own qmx.yaml —
// its memory_limit: 1G (overriding the -d memory_limit=2G below), its
// Qualimetrix\** architecture layers, and its coupling framework namespaces —
// onto every benchmark project. That is conceptually wrong and, combined with
// a pathological duplication bucket, is what makes the doctrine-dbal run OOM.
// A fresh, empty working directory turns auto-discovery into a no-op, while the
// absolute $qmxBin and per-project $path keep the invocation self-contained.
$neutralDir = sys_get_temp_dir() . '/qmx-benchmark-' . getmypid();
if (!is_dir($neutralDir) && !mkdir($neutralDir, 0o755, true) && !is_dir($neutralDir)) {
    fprintf(STDERR, "ERROR: Cannot create neutral working directory: %s\n", $neutralDir);
    exit(2);
}

fprintf(STDERR, "Benchmark regression check (%d projects)\n", count($projects));
fprintf(STDERR, "%s\n", str_repeat('=', 80));

foreach ($projects as $id => $config) {
    // Without the key, the concatenation below would silently make $path the repository
    // root — a directory that exists, analyses (vendor/ included) and seeds as this
    // project's baseline.
    if (!is_array($config) || !isset($config['path']) || !is_string($config['path']) || $config['path'] === '') {
        fprintf(STDERR, "SKIP: %s (baseline entry declares no `path`)\n", $id);
        $message = sprintf('%s: baseline entry declares no `path`', $id);
        $failures[] = $message;
        $infrastructureFailures[] = $message;

        continue;
    }

    $path = $rootDir . '/' . $config['path'];

    if (!is_dir($path)) {
        fprintf(STDERR, "SKIP: %s (path not found: %s)\n", $id, $config['path']);
        $message = sprintf('%s: benchmark path not found', $id);
        $failures[] = $message;
        $infrastructureFailures[] = $message;

        continue;
    }

    fprintf(STDERR, "  %-25s ", $id);
    $start = microtime(true);

    // Build command with optional disable-rules. An argument-vector command
    // needs no shell and therefore no escapeshellarg(): each element reaches
    // the child exactly as written, which is also what the prior shell string
    // achieved via escaping.
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

    if (isset($config['disable_rules']) && $config['disable_rules'] !== []) {
        foreach ($config['disable_rules'] as $rule) {
            $cmd[] = '--disable-rule=' . $rule;
        }
    }

    // Run from the neutral working directory so qmx does not auto-discover the
    // repo's qmx.yaml/composer.json (see the comment above $neutralDir).
    // ChildProcess::run() always captures stderr separately; it is discarded
    // below, matching the previous `2>/dev/null` shell redirect — this script
    // never surfaced the child's own stderr.
    try {
        $result = ChildProcess::run($cmd, $neutralDir);
    } catch (RuntimeException $exception) {
        fprintf(
            STDERR,
            "FAILED (could not run analysis, %.1fs): %s\n",
            round(microtime(true) - $start, 1),
            $exception->getMessage(),
        );
        $message = sprintf('%s: could not run analysis (%s)', $id, $exception->getMessage());
        $failures[] = $message;
        $infrastructureFailures[] = $message;

        continue;
    }
    $json = $result['stdout'];
    $exitCode = $result['exitCode'];
    $elapsed = round(microtime(true) - $start, 1);

    if ($exitCode > 2) {
        fprintf(STDERR, "FAILED (analysis exit code %d, %.1fs)\n", $exitCode, $elapsed);
        $message = sprintf('%s: analysis exited with code %d', $id, $exitCode);
        $failures[] = $message;
        $infrastructureFailures[] = $message;

        continue;
    }

    if ($json === '') {
        fprintf(STDERR, "FAILED (no output, %.1fs)\n", $elapsed);
        $message = sprintf('%s: analysis produced no output', $id);
        $failures[] = $message;
        $infrastructureFailures[] = $message;

        continue;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['symbols']) || !is_array($data['symbols'])) {
        fprintf(STDERR, "FAILED (invalid JSON, %.1fs)\n", $elapsed);
        $message = sprintf('%s: invalid JSON output', $id);
        $failures[] = $message;
        $infrastructureFailures[] = $message;

        continue;
    }

    if (($data['coverage']['complete'] ?? null) !== true) {
        fprintf(STDERR, "FAILED (analysis coverage incomplete or missing, %.1fs)\n", $elapsed);
        $message = sprintf('%s: analysis coverage is not complete', $id);
        $failures[] = $message;
        $infrastructureFailures[] = $message;

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
        $message = sprintf('%s: no project-level symbol in output', $id);
        $failures[] = $message;
        $infrastructureFailures[] = $message;

        continue;
    }

    // A project entry with no `expectations` block is accepted rather than fatal. Either
    // way, every canonical metric is measured and reported — what is or is not declared
    // in `expectations` governs the verdict (and, per metric, whether an absence is a
    // failure), never whether the value reaches $scores/the printed table. Feeding the
    // table from a comparison keyed by declared expectations was itself the defect this
    // guard exists to catch elsewhere: an undeclared-but-measured metric (health.typing
    // in a not-yet-migrated baseline entry) read back as a silent 0, indistinguishable
    // from a real worst-case score.
    /** @var array<string, array{0: int, 1: int}> $declaredExpectations */
    $declaredExpectations = array_key_exists('expectations', $config) ? $config['expectations'] : [];
    $metricsToMeasure = array_values(array_unique([...HEALTH_METRICS, ...array_keys($declaredExpectations)]));

    $projectFailures = [];
    $unmeasuredMetrics = [];
    $scores = [];
    foreach ($metricsToMeasure as $metric) {
        $value = $projectMetrics[$metric] ?? null;
        $isDeclared = array_key_exists($metric, $declaredExpectations);

        if ($value === null) {
            if ($isDeclared) {
                // Neither an infrastructure failure nor a disagreement about a value: the
                // metric simply was not produced by this analysis. Reported distinctly and
                // left out of $scores, so --update-baselines cannot seed nor erase it.
                $message = sprintf('%s: metric %s not found', $id, $metric);
                $failures[] = $message;
                $unmeasuredMetrics[] = $message;
            }
            // Not declared and not measured: nothing was promised about it, so it is left
            // out of $scores (printed as the absence marker) without being a failure.

            continue;
        }

        $rounded = round($value, 1);
        $scores[$metric] = $rounded;

        if (!$isDeclared) {
            // Measured, but no recorded expectation yet — kept for the table and for
            // --update-baselines to seed; nothing to compare it against.
            continue;
        }

        [$min, $max] = $declaredExpectations[$metric];
        if ($rounded < $min || $rounded > $max) {
            $message = sprintf(
                '%s: %s = %.1f, expected [%d, %d]',
                $id,
                $metric,
                $rounded,
                $min,
                $max,
            );
            $projectFailures[] = $message;
            $failures[] = $message;
        }
    }

    /** @var list<array{type: string, name: string, metrics: array<string, mixed>}> $symbolsForDistribution */
    $symbolsForDistribution = $data['symbols'];

    $results[$id] = $scores;
    $distributions[$id] = [
        'namespace' => levelDistribution($symbolsForDistribution, 'namespace'),
        'class' => levelDistribution($symbolsForDistribution, 'class'),
    ];

    // Three outcomes, three words. An expectation this run disagrees with is not the
    // same event as an expectation this run could not measure at all, and a line that
    // spells both FAIL hides the second inside the first.
    $verdict = match (true) {
        $projectFailures !== [] && $unmeasuredMetrics !== [] => 'FAIL+UNMEASURED',
        $unmeasuredMetrics !== [] => 'UNMEASURED',
        $projectFailures !== [] => 'FAIL',
        default => 'OK',
    };
    $unmeasuredAcrossRun = [...$unmeasuredAcrossRun, ...$unmeasuredMetrics];
    fprintf(STDERR, "%-15s (%.1fs)\n", $verdict, $elapsed);
}

// Print summary
fprintf(STDERR, "\n%s\n", str_repeat('=', 80));

if (count($results) > 0) {
    fprintf(
        STDERR,
        "\n%-25s %6s %6s %6s %6s %6s %6s\n",
        'Project',
        'cmplx',
        'cohsn',
        'cplng',
        'maint',
        'typng',
        'ovral',
    );
    fprintf(STDERR, "%s\n", str_repeat('-', 74));

    foreach ($results as $id => $scores) {
        fprintf(
            STDERR,
            "%-25s %s %s %s %s %s %s\n",
            $id,
            formatScoreCell($scores, 'health.complexity'),
            formatScoreCell($scores, 'health.cohesion'),
            formatScoreCell($scores, 'health.coupling'),
            formatScoreCell($scores, 'health.maintainability'),
            formatScoreCell($scores, 'health.typing'),
            formatScoreCell($scores, 'health.overall'),
        );
    }
}

// Baseline replacement is atomic at the project-set level: a partial corpus must never
// ratchet only the projects that happened to finish. Writing requires no infrastructure
// failure anywhere in the run and a complete corpus; an expectation mismatch does not
// block it — recalibration exists to correct exactly those.
if ($updateBaselines && $infrastructureFailures === [] && count($results) === count($projects)) {
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

    // A snapshot, not a ratchet: it carries no accepted range to compare against, so it is
    // regenerated on every successful update rather than reviewed for drift. Kept in its
    // own file, at its own regeneration cadence, so namespace/class-level jitter is never
    // mistaken for a project-level regression baked into docs/internal/benchmark-baselines.json.
    $distributionPayload = [
        'version' => 1,
        'updated_at' => date('Y-m-d'),
        'description' => 'Per-project namespace- and class-level health.* distributions (median, p25, p75, iqr), sampled alongside docs/internal/benchmark-baselines.json by scripts/benchmark-regression.php --update-baselines.',
        'projects' => $distributions,
    ];
    $encodedDistributions = json_encode($distributionPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encodedDistributions === false || file_put_contents($distributionFile, $encodedDistributions . "\n") === false) {
        fprintf(STDERR, "ERROR: Cannot write distribution file: %s\n", $distributionFile);
        exit(2);
    }
    fprintf(STDERR, "Namespace/class distributions written to: %s\n", $distributionFile);

    if ($unmeasuredAcrossRun !== []) {
        fprintf(
            STDERR,
            "\nEXPECTED BUT NOT MEASURED (%d) — left standing in the baseline, nothing to seed them from:\n",
            count($unmeasuredAcrossRun),
        );

        foreach ($unmeasuredAcrossRun as $unmeasured) {
            fprintf(STDERR, "  - %s\n", $unmeasured);
        }

        exit(1);
    }

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
