<?php

declare(strict_types=1);

/**
 * Rewrite the two MEASURED columns of the shorthand-scope round's axis-B
 * assignment table from a grid that has just been taken.
 *
 * WHY THIS IS A WRITE AND NEVER A CHECK. The table has twelve columns and only
 * two of them are measurements. `today_verdict` is what the grid says now;
 * `today_coexistence` is what the ledger declares now. The other ten are
 * decisions and predictions: `assigned` is a choice this round makes, and the
 * three `post_cure_*` columns are claims that only a run against the CURED
 * product can check. A mode that rewrote those from a run would be writing down
 * the answer it was supposed to be checking.
 *
 * WHY THE TABLE IS NOT REGENERATED WHOLE. `today_verdict` at the magnitudes
 * this round introduces cannot be predicted from observations taken at the old
 * ones: what `both` carries at a contested pointer IS which key won, and that
 * is the thing under measurement. A generator that produced it would be a model
 * of the product, written by the round that is changing the product. So the
 * promise is narrowed instead of the oracle widened.
 *
 * Exit codes follow the finding gate's own convention for a derive mode:
 *
 *   0  nothing to write — every measured column already agrees with the grid
 *   4  the table was rewritten
 *   5  the grid it would have been rewritten from could not be read
 *
 * Never 0 on a write: "it wrote" and "it agreed" are different facts and a
 * caller that cannot tell them apart will read one as the other.
 */

namespace Qualimetrix\PromiseEffect;

/** @return list<list<string>> */
function readRows(string $path): array
{
    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        fwrite(\STDERR, "promise-effect-axis-b-rows: cannot read " . $path . "\n");

        exit(5);
    }

    $rows = [];

    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\r\n");

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $rows[] = explode("\t", $line);
    }

    fclose($handle);

    return $rows;
}

$root = \dirname(__DIR__);
$table = $root . '/docs/internal/plans/shorthand-scope/measurement/axis-b-rows.tsv';
$grid = $root . '/docs/internal/generated/promise-effect/verdicts.tsv';
$ledger = $root . '/promise-effect/promise-ledger.tsv';

$verdicts = [];

foreach (readRows($grid) as $row) {
    if ($row[0] !== 'B') {
        continue;
    }

    // `pair|<rule>|<key_a>|<key_b>|<source_scope>|<kind>` — the same four
    // coordinates the table's first four columns carry, in the same order.
    $parts = explode('|', $row[1]);

    if (\count($parts) < 6) {
        continue;
    }

    $verdicts[$parts[1] . '|' . $parts[2] . '|' . $parts[3] . '|' . $parts[5]] = $row[4];
}

if ($verdicts === []) {
    fwrite(\STDERR, "promise-effect-axis-b-rows: the grid carries no axis-B cell, so there is nothing to write from\n");

    exit(5);
}

$coexistence = [];

foreach (readRows($ledger) as $row) {
    if ($row[0] !== 'pair' || \count($row) < 9 || $row[4] !== 'same-source') {
        continue;
    }

    $kind = $row[8];
    $kind = preg_replace('/^.*kind=/s', '', $kind) ?? '';
    $kind = trim(preg_replace('/[;|].*$/s', '', $kind) ?? '');

    $coexistence[$row[1] . '|' . $row[2] . '|' . $row[3] . '|' . $kind] = $row[5];
}

$lines = explode("\n", (string) file_get_contents($table));
$changed = 0;
$missing = [];

foreach ($lines as $index => $line) {
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    $cells = explode("\t", $line);

    if ($cells[0] === 'rule') {
        continue;
    }

    // Seven, because the two columns written back are the sixth and the
    // seventh. A shorter row is a malformed table, and half-writing it would
    // leave a verdict beside a column that had moved under it.
    if (\count($cells) < 7) {
        fwrite(\STDERR, 'promise-effect-axis-b-rows: line ' . ($index + 1) . " carries fewer than seven columns\n");

        exit(5);
    }

    $key = $cells[0] . '|' . $cells[1] . '|' . $cells[2] . '|' . $cells[3];

    if (!isset($verdicts[$key])) {
        $missing[] = $key;

        continue;
    }

    $before = [$cells[5], $cells[6]];
    $cells[5] = $verdicts[$key];
    $cells[6] = $coexistence[$key] ?? $cells[6];

    if ($before !== [$cells[5], $cells[6]]) {
        ++$changed;
    }

    $lines[$index] = implode("\t", $cells);
}

// A row the grid does not carry is a table that has drifted from the ledger,
// which is a fact about the round rather than about this run. It is named and
// the write still happens for every row that DID join: a silent skip would let
// the table keep a stale verdict under a green derive.
foreach ($missing as $key) {
    fwrite(\STDERR, 'promise-effect-axis-b-rows: no axis-B cell for ' . $key . "\n");
}

if ($changed === 0) {
    printf("axis-b-rows: %d row(s) already agree with the grid, nothing written\n", \count($verdicts));

    exit(0);
}

file_put_contents($table, implode("\n", $lines));
printf("axis-b-rows: %d measured column pair(s) rewritten from the grid\n", $changed);

exit(4);
