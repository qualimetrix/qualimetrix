<?php

declare(strict_types=1);

/**
 * Rewrite the two MEASURED columns of an axis-B assignment table from a grid
 * that has just been taken. The table is given as the one argument.
 *
 * WHY THIS IS A WRITE AND NEVER A CHECK. The table has twelve columns and only
 * two of them are measurements. `today_verdict` is what the grid says now;
 * `today_coexistence` is what the ledger declares now. The other ten are
 * decisions and predictions: `assigned` is a choice a round makes, and the
 * three `post_cure_*` columns are claims that only a run against the cured
 * product can check. A mode that rewrote those from a run would be writing down
 * the answer it was supposed to be checking.
 *
 * WHY THE TABLE IS NOT REGENERATED WHOLE. `today_verdict` at one set of
 * magnitudes is not predictable from observations taken at another — what
 * `both` carries at a contested pointer IS which key won, and that is the thing
 * under measurement. A generator producing it would be a model of the product,
 * written by the round that is changing the product. So the promise is narrowed
 * instead of the oracle widened.
 *
 * EVERY ROW JOINS OR NOTHING IS WRITTEN. A table row the grid does not carry,
 * or whose kind the ledger does not declare, means the table has drifted from
 * the artefacts it is derived from. Writing the rows that did join would leave
 * the others holding a stale verdict under a successful exit, which is the one
 * outcome this mode exists to prevent. So the join is resolved for every row
 * first, and a single failure refuses the whole write and names each row.
 *
 * Exit codes:
 *
 *   0  every row joined and both measured columns already agree — nothing written
 *   4  every row joined and the table was rewritten
 *   5  an input could not be read, a row did not join, or the write failed
 *
 * Never 0 on a write: "it wrote" and "it agreed" are different facts and a
 * caller that cannot tell them apart will read one as the other.
 */

namespace Qualimetrix\PromiseEffect;

require_once __DIR__ . '/promise-effect/Ledger.php';

/** @return list<list<string>> */
function readRows(string $path): array
{
    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        fwrite(\STDERR, 'promise-effect-axis-b-rows: cannot read ' . $path . "\n");

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

// The table is named by the caller and never by this file. A table of this
// shape belongs to whatever round is asking the question, and a round's
// records are removed once its decisions have moved to their permanent owners
// — so a path written here would outlive the file it points at. The same rule
// is held by `governance/PlanningRecords/PlanningRecordIsolationTest.php` for
// every executable source in the tree.
$table = $argv[1] ?? '';

if ($table === '' || !is_file($table)) {
    fwrite(\STDERR, "usage: php scripts/promise-effect-axis-b-rows.php <axis-b-assignment-table.tsv>\n");

    exit(5);
}

$verdicts = [];

foreach (readRows($root . '/docs/internal/generated/promise-effect/verdicts.tsv') as $row) {
    if (($row[0] ?? '') !== 'B') {
        continue;
    }

    if (\count($row) < 5) {
        fwrite(\STDERR, "promise-effect-axis-b-rows: the grid carries an axis-B row with fewer than five columns\n");

        exit(5);
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

// Through `Ledger::load()` and never through a second parser of its own. The
// kind lives in a free-text note column, and `Ledger::pairKind()` REFUSES a row
// whose note carries no `kind=` marker. A regex here would absorb exactly that
// row instead — yielding whatever follows the last marker, or the whole note
// when there is none — and the coexistence column would then be frozen in
// silence while the verdict column was rewritten around it.
try {
    $ledger = Ledger::load($root);
} catch (LedgerError $error) {
    fwrite(\STDERR, 'promise-effect-axis-b-rows: ' . $error->getMessage() . "\n");

    exit(5);
}

$coexistence = [];

foreach ($ledger->pairs as $pair) {
    if ($pair->sourceScope !== 'same-source') {
        continue;
    }

    $coexistence[$pair->rule . '|' . $pair->keyA . '|' . $pair->keyB . '|' . $pair->kind] = $pair->coexistence;
}

$lines = explode("\n", (string) file_get_contents($table));
$resolved = [];
$unjoined = [];

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

    // Both columns are diagnosed, not just the verdict. An unjoined coexistence
    // key used to fall through a `??` back onto the cell's own old value, so a
    // ledger the table had drifted from produced a successful run that had
    // rewritten one measured column and frozen the other.
    $missing = [];

    if (!isset($verdicts[$key])) {
        $missing[] = 'no axis-B cell in the grid';
    }

    if (!isset($coexistence[$key])) {
        $missing[] = 'no same-source pair row in the ledger';
    }

    if ($missing !== []) {
        $unjoined[] = 'line ' . ($index + 1) . ' (' . $key . '): ' . implode('; ', $missing);

        continue;
    }

    $resolved[$index] = [$cells, $verdicts[$key], $coexistence[$key]];
}

if ($unjoined !== []) {
    fwrite(\STDERR, 'promise-effect-axis-b-rows: ' . \count($unjoined) . " row(s) did not join; nothing written\n");

    foreach ($unjoined as $row) {
        fwrite(\STDERR, '  ' . $row . "\n");
    }

    exit(5);
}

$changed = 0;

foreach ($resolved as $index => [$cells, $verdict, $promise]) {
    $before = [$cells[5], $cells[6]];
    $cells[5] = $verdict;
    $cells[6] = $promise;

    if ($before !== [$cells[5], $cells[6]]) {
        ++$changed;
    }

    $lines[$index] = implode("\t", $cells);
}

if ($changed === 0) {
    printf("axis-b-rows: %d row(s) joined and already agree with the grid, nothing written\n", \count($resolved));

    exit(0);
}

// Checked, because a failed write that printed "rewritten" and exited 4 would
// be the same false success as an unjoined row under a green exit.
if (file_put_contents($table, implode("\n", $lines)) === false) {
    fwrite(\STDERR, 'promise-effect-axis-b-rows: could not write ' . $table . "\n");

    exit(5);
}

printf("axis-b-rows: %d of %d joined row(s) rewritten from the grid\n", $changed, \count($resolved));

exit(4);
