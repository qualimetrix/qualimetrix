#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates the versioned snapshot of what a self-analysis run of `src/`
 * suppresses — `docs/internal/generated/suppression/{composition,inert}.tsv`.
 *
 * See PLAN.md, rule-vocabulary Ш6 decision (м)/(н). Nothing today compares
 * this composition run to run: Ш5e2b's precedent is that removing one
 * point threshold shifted the suppressed count 55 -> 56 and no test, no
 * format and no exit code noticed. This script is the oracle `--check`
 * compares a fresh measurement against.
 *
 * **Run parameters are fixed here, not left to a caller.** The composition
 * is a function of them, and a forgotten flag would make the snapshot
 * unreadable as evidence:
 *   - target `src` from the repository root, so `bin/qmx` auto-discovers
 *     this repository's own `qmx.yaml` rather than a caller's working
 *     directory;
 *   - no `--baseline`: an evolving `qmx-baseline.json` must not change what
 *     this snapshot measures, and the `baseline` mechanism simply reports
 *     zero here as a result — that is the documented cost of decision (м),
 *     not a bug in this script;
 *   - `--format=suppressed`, which by decision (д) arms the same per-rule
 *     ledger capture `--show-suppressed` would, so both halves of the
 *     mechanism vocabulary are populated;
 *   - `--workers=0 --no-cache` for one deterministic, single-threaded pass.
 *
 * **The key, and why only one mechanism needs its suppressor normalized.**
 * Decision (м) fixes the key as mechanism x suppressor x channel x
 * canonical subject, ordered by that key rather than by encounter order.
 * The two probes the DoD names are, concretely:
 *   (a) removing any `@qmx-ignore` directive or `@qmx-threshold` override
 *       must change some row's count (or remove/add a row) — the very
 *       thing Ш5e2b's precedent says nothing catches today;
 *   (b) renaming a local (non-subject) symbol, or shifting line numbers by
 *       inserting blank lines, must change no row at all.
 *
 * `suppressed`'s JSON does not publish `subject->toCanonical()` (the
 * declaration path with its rename-proof ordinal) — only `symbol`
 * (`symbolPath->toString()`, namespace/class/method names, no line or
 * ordinal) and `file`. That pair is this snapshot's stand-in for "canonical
 * subject": it carries no line number, so it survives probe (b), and
 * combined with `channel` it is precise enough for every case measured on
 * this repository's `src`. The gap it accepts: two non-first declarations
 * of the same name in one file (an ordinal collision) would collapse into
 * one row instead of two — a granularity loss, not a blind spot, because a
 * directive removed from either declaration still changes that row's count.
 *
 * Per mechanism, the raw `suppressor` field is:
 *   - `suppression`      -> `file:line` of the `@qmx-ignore*` directive.
 *                            The only one of the seven that embeds a line
 *                            number, so the only one normalized here: the
 *                            trailing `:line` is stripped, leaving the file.
 *                            `file` + `symbol` already disambiguate distinct
 *                            declarations sharing that file, so nothing is
 *                            lost that those two fields do not already carry.
 *   - `path-exclusion`,
 *     `namespace-exclusion`,
 *     `rule-path-exclusion`,
 *     `rule-namespace-exclusion` -> a configured glob/namespace pattern or a
 *                            producer rule name. Neither ever contains a
 *                            line number; used unchanged.
 *   - `baseline`          -> `<subject->toCanonical()> <code>`, already
 *                            ordinal-based rather than line-based; unchanged.
 *   - `git-scope`         -> the configured git reference; unchanged.
 *
 * A sixth-mechanism drift would show up here as an unrecognized value from
 * `bin/qmx`'s own vocabulary (see {@see SuppressionMechanism} for the
 * dictionary-completeness guarantee); this script does not re-enumerate the
 * seven values itself.
 */

function generateSuppressionSnapshot(): int
{
    $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    $check = in_array('--check', $arguments, true);

    $unknown = array_values(array_filter(
        $arguments,
        static fn(string $argument): bool => $argument !== '--check',
    ));

    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown argument: ' . implode(', ', $unknown) . "\n");

        return 2;
    }

    $root = dirname(__DIR__);
    $compositionPath = $root . '/docs/internal/generated/suppression/composition.tsv';
    $inertPath = $root . '/docs/internal/generated/suppression/inert.tsv';

    $measurement = measureSuppressionComposition($root);

    if (is_string($measurement)) {
        fwrite(STDERR, $measurement);

        return 2;
    }

    [$compositionContent, $inertContent, $rowCount, $inertCount] = $measurement;

    if ($check) {
        // Both are checked unconditionally (not short-circuited) so a run with
        // both files stale reports both mismatches in one invocation.
        $compositionStale = checkOne($compositionPath, $compositionContent);
        $inertStale = checkOne($inertPath, $inertContent);

        return $compositionStale || $inertStale
            ? 1
            : reportUpToDate($compositionPath, $inertPath, $rowCount, $inertCount);
    }

    writeSuppressionSnapshotAtomically($compositionPath, $compositionContent);
    writeSuppressionSnapshotAtomically($inertPath, $inertContent);

    fwrite(STDOUT, sprintf(
        "Wrote %s (%d rows) and %s (%d rows).\n",
        $compositionPath,
        $rowCount,
        $inertPath,
        $inertCount,
    ));

    return 0;
}

/**
 * Composition TSV, inert TSV, row count, inert count — or an error message
 * on infrastructure failure (process could not start, output was not the
 * expected JSON), never a finding-level exit code, which `bin/qmx check`
 * uses even on a clean, fully measured run.
 *
 * @return array{0: string, 1: string, 2: int, 3: int}|string
 */
function measureSuppressionComposition(string $root): array|string
{
    $qmxBin = $root . '/bin/qmx';
    $cmd = sprintf(
        '%s %s check src --format=suppressed --workers=0 --no-cache',
        escapeshellarg(\PHP_BINARY),
        escapeshellarg($qmxBin),
    );

    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);

    if (!is_resource($process)) {
        return "Could not start `$cmd`.\n";
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    // 0 = clean, 1 = warnings, 2 = errors — all three are a complete,
    // successfully measured run (see CheckCommandDefinition's --fail-on
    // doc). Anything else is a config/input failure this snapshot cannot
    // measure through.
    if ($exitCode > 2 || $stdout === false) {
        return sprintf(
            "`%s` exited %d, which is not a measured run (0-2). stderr:\n%s\n",
            $cmd,
            $exitCode,
            $stderr === false ? '(none)' : $stderr,
        );
    }

    try {
        /** @var mixed $decoded */
        $decoded = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        return sprintf("`%s` did not print the expected JSON: %s\n", $cmd, $e->getMessage());
    }

    if (!is_array($decoded) || !isset($decoded['suppressed'], $decoded['neverMatched'])
        || !is_array($decoded['suppressed']) || !is_array($decoded['neverMatched'])
    ) {
        return sprintf("`%s` printed JSON without the expected `suppressed`/`neverMatched` keys.\n", $cmd);
    }

    /** @var list<array<string, mixed>> $suppressed */
    $suppressed = $decoded['suppressed'];
    /** @var list<array<string, mixed>> $neverMatched */
    $neverMatched = $decoded['neverMatched'];

    $counts = [];

    foreach ($suppressed as $entry) {
        $key = compositionKey($entry);
        $counts[$key] ??= 0;
        $counts[$key]++;
    }

    $inertKeys = [];

    foreach ($neverMatched as $entry) {
        $mechanism = (string) ($entry['mechanism'] ?? '');
        $suppressor = (string) ($entry['suppressor'] ?? '');
        $inertKeys[$mechanism . "\t" . $suppressor] = true;
    }

    ksort($counts, \SORT_STRING);
    ksort($inertKeys, \SORT_STRING);

    return [
        renderComposition($counts),
        renderInert(array_keys($inertKeys)),
        count($counts),
        count($inertKeys),
    ];
}

/**
 * @param array<string, mixed> $entry One element of the `suppressed` list
 */
function compositionKey(array $entry): string
{
    $mechanism = (string) ($entry['mechanism'] ?? '');
    $suppressor = normalizeSuppressor($mechanism, (string) ($entry['suppressor'] ?? ''));
    $channel = (string) ($entry['channel'] ?? '');
    $file = $entry['file'] === null ? '(no file)' : (string) $entry['file'];
    $symbol = (string) ($entry['symbol'] ?? '');

    return implode("\t", [$mechanism, $suppressor, $channel, $file, $symbol]);
}

/**
 * Strips the `:line` suffix `suppression`'s raw suppressor carries — see the
 * file docblock for why this is the only mechanism that needs it.
 */
function normalizeSuppressor(string $mechanism, string $suppressor): string
{
    if ($mechanism !== 'suppression') {
        return $suppressor;
    }

    $lastColon = strrpos($suppressor, ':');

    return $lastColon === false ? $suppressor : substr($suppressor, 0, $lastColon);
}

/**
 * @param array<string, int> $counts Key (see compositionKey()) => count, already sorted
 */
function renderComposition(array $counts): string
{
    $header = "mechanism\tsuppressor\tchannel\tfile\tsymbol\tcount";
    $lines = [];

    foreach ($counts as $key => $count) {
        $lines[] = $key . "\t" . $count;
    }

    return $header . "\n" . ($lines === [] ? '' : implode("\n", $lines) . "\n");
}

/**
 * @param list<string> $keys Already-sorted `mechanism\tsuppressor` keys
 */
function renderInert(array $keys): string
{
    $header = "mechanism\tsuppressor";

    return $header . "\n" . ($keys === [] ? '' : implode("\n", $keys) . "\n");
}

function checkOne(string $path, string $fresh): bool
{
    $onDisk = is_file($path) ? file_get_contents($path) : false;

    if ($onDisk === false) {
        fwrite(STDERR, sprintf("%s does not exist. Run `composer suppression-snapshot` to generate it.\n", $path));

        return true;
    }

    if ($onDisk !== $fresh) {
        fwrite(STDERR, describeSuppressionSnapshotMismatch($onDisk, $fresh, $path));

        return true;
    }

    return false;
}

function reportUpToDate(string $compositionPath, string $inertPath, int $rowCount, int $inertCount): int
{
    fwrite(STDOUT, sprintf(
        "Checked %s (%d rows) and %s (%d rows): up to date.\n",
        $compositionPath,
        $rowCount,
        $inertPath,
        $inertCount,
    ));

    return 0;
}

function describeSuppressionSnapshotMismatch(string $onDisk, string $fresh, string $path): string
{
    $onDiskLines = explode("\n", $onDisk);
    $freshLines = explode("\n", $fresh);
    $lineCount = max(count($onDiskLines), count($freshLines));

    $diffs = [];

    for ($line = 0; $line < $lineCount; $line++) {
        $committed = $onDiskLines[$line] ?? '<no such line on disk>';
        $regenerated = $freshLines[$line] ?? '<line removed by regeneration>';

        if ($committed !== $regenerated) {
            $diffs[] = sprintf(
                "  line %d:\n    committed:    %s\n    regenerated:  %s",
                $line + 1,
                $committed,
                $regenerated,
            );
        }
    }

    $shown = array_slice($diffs, 0, 10);
    $summary = sprintf(
        "%s is stale: %d of %d line(s) differ from a fresh measurement. Run `composer suppression-snapshot` to refresh it.\n",
        $path,
        count($diffs),
        $lineCount,
    );

    if (count($diffs) > count($shown)) {
        $summary .= sprintf("(showing first %d of %d diffs)\n", count($shown), count($diffs));
    }

    return $summary . implode("\n", $shown) . "\n";
}

function writeSuppressionSnapshotAtomically(string $path, string $contents): void
{
    $tmpPath = $path . '.tmp.' . getmypid();
    file_put_contents($tmpPath, $contents);
    rename($tmpPath, $path);
}

// Include-safe: requiring this file from a test only defines the functions
// above (see e.g. normalizeSuppressor()), it does not run a self-analysis.
if (realpath((string) ($_SERVER['argv'][0] ?? '')) === realpath(__FILE__)) {
    exit(generateSuppressionSnapshot());
}
