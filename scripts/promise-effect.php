<?php

declare(strict_types=1);

/**
 * The promise-effect oracle: where a recognised key does something other than
 * what its name and its documentation promise.
 *
 * The sibling stand `scripts/input-doors.php` measures a different class — a
 * value pointing at nothing, and whether the product says so. Here the value
 * points at something, silence is often lawful, and the defect is in the
 * EFFECT. What is borrowed from the sibling is its discipline, not its logic:
 * raw observations are stored and re-judged, not knowing yields the worse
 * verdict, and no second normalization list is started.
 *
 * Usage:
 *   php scripts/promise-effect.php                write the verdict grid
 *   php scripts/promise-effect.php --check        0 fresh, 1 drift or a red outcome
 *   php scripts/promise-effect.php --before       re-judge the frozen raw observations
 *   php scripts/promise-effect.php --freeze-before --reason='…'
 *   php scripts/promise-effect.php --axis=A,B,D   narrow the run (never evidence on its own)
 *   php scripts/promise-effect.php --stability    measure twice, demand the same text
 *
 * Exit codes: 0 clean, 1 a red outcome on axis A or D, 2 a declaration that
 * cannot be read, 3 a probe that could not be taken (see 02 §4: a run that did
 * not confirm its postcondition is not an observation).
 *
 * `--before` carries the DEFECT FLOOR, and that is where the floor belongs: it
 * is a claim about the classifier reading a known pre-cure tree, so it exits 1
 * there when a declared row stops being recognised. The live grid is held to
 * the round's own claim about what it cured instead — see `Floor`.
 */

namespace Qualimetrix\PromiseEffect;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/promise-effect/Ledger.php';
require __DIR__ . '/promise-effect/Declarations.php';
require __DIR__ . '/promise-effect/InProcess.php';
require __DIR__ . '/promise-effect/ProcessProbe.php';
require __DIR__ . '/promise-effect/Classifier.php';
require __DIR__ . '/promise-effect/Stand.php';
require __DIR__ . '/promise-effect/Floor.php';
require __DIR__ . '/promise-effect/Stamp.php';

/**
 * @param list<string> $argv
 *
 * @return array<string, string>
 */
function parseArguments(array $argv): array
{
    $options = [];

    foreach (\array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $halves = explode('=', substr($argument, 2), 2);
        $options[$halves[0]] = $halves[1] ?? '1';
    }

    return $options;
}

/** @param list<Cell> $cells */
function render(array $cells): string
{
    $lines = [];

    foreach ($cells as $cell) {
        $lines[] = implode("\t", [
            $cell->axis,
            $cell->key,
            $cell->probe,
            $cell->point,
            $cell->verdict,
            $cell->defect ? 'yes' : 'no',
            $cell->status,
            str_replace(["\t", "\n"], ' ', $cell->decidedBy),
        ]);
    }

    sort($lines, \SORT_STRING);

    return "axis\trow\tprobe\tpoint\tverdict\tdefect\tledger_status\tdecided_by\n" . implode("\n", $lines) . "\n";
}

/** @param list<array{string, string, string, string, string}> $raw */
function renderRaw(array $raw): string
{
    $lines = [];

    foreach ($raw as [$axis, $key, $side, $outcome, $text]) {
        $lines[] = implode("\t", [$axis, $key, $side, $outcome, str_replace(["\t", "\n", "\r"], ' ', $text)]);
    }

    sort($lines, \SORT_STRING);

    return "axis\trow\tside\toutcome\tobservation\n" . implode("\n", $lines) . "\n";
}

/** @return list<array{string, string, string, string, string}> */
function readRaw(string $path): array
{
    $lines = file($path, \FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new LedgerError('cannot read ' . $path . ' — the "before" half was never frozen');
    }

    $rows = [];

    foreach (\array_slice($lines, 1) as $line) {
        if ($line === '') {
            continue;
        }

        /** @var array{string, string, string, string, string} $cells */
        $cells = array_pad(explode("\t", $line, 5), 5, '');
        $rows[] = $cells;
    }

    return $rows;
}

/**
 * @param list<Cell> $cells
 *
 * @return array<string, int>
 */
function summarize(array $cells, string $axis): array
{
    $counts = [];

    foreach ($cells as $cell) {
        if ($cell->axis !== $axis) {
            continue;
        }

        $counts[$cell->verdict] = ($counts[$cell->verdict] ?? 0) + 1;
    }

    ksort($counts);

    return $counts;
}

$root = \dirname(__DIR__);
/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$arguments = parseArguments($argv);
$scratch = $arguments['scratch'] ?? sys_get_temp_dir() . '/qmx-promise-effect';
$axes = explode(',', $arguments['axis'] ?? 'A,B,D');

$snapshotDirectory = $root . '/' . Stand::SNAPSHOT_DIR;

if (!is_dir($snapshotDirectory)) {
    mkdir($snapshotDirectory, 0o775, true);
}

try {
    $ledger = Ledger::load($root);
    $declarations = Declarations::load($root);
    $inProcess = new InProcess($scratch);
    $process = new ProcessProbe($root, $scratch);
    $stand = new Stand($root, $ledger, $declarations, $inProcess, $process);
} catch (LedgerError $error) {
    fwrite(\STDERR, 'promise-effect: ' . $error->getMessage() . "\n");

    exit(2);
}

if (isset($arguments['before'])) {
    // Re-judged, never replayed: the frozen file holds raw observations, and
    // today's classifier is applied to them. Editing the classifier therefore
    // moves BOTH halves of the pair, which is the property the freeze exists
    // to keep.
    $frozen = $stand->before(readRaw($snapshotDirectory . '/observations-before/raw.tsv'));

    foreach (['A', 'B', 'D'] as $axis) {
        printf("axis %s (before)\n", $axis);

        foreach (summarize($frozen, $axis) as $verdict => $count) {
            printf("  %-22s %d\n", $verdict, $count);
        }
    }

    printf("\n  %-22s %d\n", 'defects', \count(array_filter($frozen, static fn(Cell $cell): bool => $cell->defect)));

    // The floor belongs HERE. It is a claim about the classifier reading a
    // known pre-cure tree — `01-promise.md` states it of the snapshot BEFORE —
    // and on this half it is meaningful whether or not the product was since
    // repaired. Judging it on the live grid instead made every successful cure
    // a floor miss, which is how a cured product came to exit 1.
    $floorMisses = Floor::load($root)->missesOnTheFrozenHalf($frozen);

    printf(
        "  %-22s %s\n",
        'defect floor',
        $floorMisses === [] ? 'reproduced on the pre-cure half' : \count($floorMisses) . ' row(s) not recognised',
    );

    foreach ($floorMisses as $miss) {
        fwrite(\STDERR, 'FLOOR: ' . $miss . "\n");
    }

    exit($floorMisses === [] ? 0 : 1);
}

if (isset($arguments['stability'])) {
    // A second, independent measurement — new scratch, new container, new
    // memo — and a demand that it render the same text. Repeating the same
    // Stand would only prove the memo works, which is not the question:
    // nondeterminism in this stand would come from the product runs and the
    // temporary directories they are given, and both are rebuilt here.
    $second = new Stand(
        $root,
        Ledger::load($root),
        Declarations::load($root),
        $secondInProcess = new InProcess($scratch . '-stability'),
        new ProcessProbe($root, $scratch . '-stability'),
    );

    $problems = [...$stand->proveRefusalFraming(), ...$second->proveRefusalFraming()];

    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(\STDERR, 'REFUSAL FRAMING CONTROL: ' . $problem . "\n");
        }

        exit(3);
    }

    // Only axis A consults a witness; taking 54 process runs twice to judge a
    // narrowed run would price the stability check out of ever being used.
    if (\in_array('A', $axes, true)) {
        $stand->takeWitnesses();
        $second->takeWitnesses();
    }

    $texts = [];

    foreach ([$stand, $second] as $round) {
        $cells = [];

        if (\in_array('A', $axes, true)) {
            $cells = [...$cells, ...$round->axisA()];
        }

        if (\in_array('B', $axes, true)) {
            $cells = [...$cells, ...$round->axisB()];
        }

        if (\in_array('D', $axes, true)) {
            $cells = [...$cells, ...$round->axisD()];
        }

        $texts[] = render($cells);
    }

    $differences = 0;
    $firstLines = explode("\n", $texts[0]);
    $secondLines = explode("\n", $texts[1]);

    foreach ($firstLines as $index => $line) {
        if (($secondLines[$index] ?? null) === $line) {
            continue;
        }

        ++$differences;

        if ($differences <= 10) {
            fwrite(\STDERR, 'UNSTABLE: ' . $line . "\n      vs: " . ($secondLines[$index] ?? '(absent)') . "\n");
        }
    }

    printf(
        "Stability over axes %s: %d line(s) of %d differ between two independent measurements.\n",
        implode(',', $axes),
        $differences,
        \count($firstLines),
    );

    exit($differences === 0 ? 0 : 1);
}

$framingProblems = $stand->proveRefusalFraming();

if ($framingProblems !== []) {
    foreach ($framingProblems as $problem) {
        fwrite(\STDERR, 'REFUSAL FRAMING CONTROL: ' . $problem . "\n");
    }

    exit(3);
}

if (\in_array('A', $axes, true)) {
    $stand->takeWitnesses();
}

$cells = [];

if (\in_array('A', $axes, true)) {
    $cells = [...$cells, ...$stand->axisA()];
}

if (\in_array('B', $axes, true)) {
    $cells = [...$cells, ...$stand->axisB()];
}

if (\in_array('D', $axes, true)) {
    $cells = [...$cells, ...$stand->axisD()];
}

$rendered = render($cells);
$target = $snapshotDirectory . '/verdicts.tsv';
$spanProblems = 0;

if (isset($arguments['check'])) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';

    if ($current !== $rendered) {
        fwrite(\STDERR, "promise-effect verdicts are stale: run composer promise-effect\n");

        exit(1);
    }
} else {
    file_put_contents($target, $rendered);
    file_put_contents($snapshotDirectory . '/' . basename(Stamp::PATH), (new Stamp($root))->render());
}

// The cheap check reconstructs which cells the ledger owes; this is where that
// reconstruction is held to the generators it mirrors. Without the assertion
// the two could drift apart and the aggregate would keep calling a grid fresh
// that the expensive run no longer produces.
if (\count($axes) === 3) {
    $produced = [];

    foreach ($cells as $cell) {
        $produced[$cell->key] = ($produced[$cell->key] ?? 0) + 1;
    }

    foreach ($stand->expectedKeys() as $key => $count) {
        if (($produced[$key] ?? 0) !== $count) {
            fwrite(\STDERR, 'SPAN: the ledger owes ' . $count . ' cell(s) for ' . $key . ', the run produced ' . ($produced[$key] ?? 0) . "\n");
            ++$spanProblems;
        }

        unset($produced[$key]);
    }

    foreach (array_keys($produced) as $key) {
        fwrite(\STDERR, 'SPAN: the run produced ' . $key . ', which no ledger row owes' . "\n");
        ++$spanProblems;
    }
}

if (isset($arguments['freeze-before'])) {
    // The snapshot stores RAW observations, never verdicts: one classifier
    // judges both halves of the pair, and a frozen verdict would let a later
    // edit of the classifier split them in silence.
    $head = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));

    if (!str_starts_with($head, '6a833ab8')) {
        fwrite(\STDERR, 'promise-effect: the "before" shot must be taken on 6a833ab8, HEAD is ' . $head . "\n");

        exit(3);
    }

    if (!is_dir($snapshotDirectory . '/observations-before')) {
        mkdir($snapshotDirectory . '/observations-before', 0o775, true);
    }

    // 02 §10: the shot is retaken only with a reason, and editing the
    // classifier is not one. The reason is written into the file rather than
    // left in a commit message, because the file is what the next reader of
    // the frozen half has in front of them.
    $reason = $arguments['reason'] ?? '';

    if (trim($reason) === '') {
        fwrite(\STDERR, "promise-effect: --freeze-before needs --reason=… — 02 §10 allows a retake only with one\n");

        exit(3);
    }

    file_put_contents($snapshotDirectory . '/observations-before/raw.tsv', renderRaw($stand->rawObservations()));
    file_put_contents(
        $snapshotDirectory . '/observations-before/shot.txt',
        "commit\t" . $head . "\n"
        . "taken\t" . gmdate('Y-m-d') . "\n"
        . "axes\t" . implode(',', $axes) . "\n"
        . "reason\t" . str_replace(["\t", "\n"], ' ', $reason) . "\n",
    );
}

$defects = 0;
$red = 0;

foreach (['A', 'B', 'D'] as $axis) {
    if (!\in_array($axis, $axes, true)) {
        continue;
    }

    printf("axis %s\n", $axis);

    foreach (summarize($cells, $axis) as $verdict => $count) {
        printf("  %-22s %d\n", $verdict, $count);
    }

    // Counted from the judgement, not from the label: a framed refusal of a
    // form the ledger promised is a defect wearing the REFUSES label, and
    // counting labels would lose exactly the class this round measures.
    foreach ($cells as $cell) {
        if ($cell->axis !== $axis || !$cell->defect) {
            continue;
        }

        ++$defects;

        // Axis B is measured, not cured, in this round: no owner holds a
        // mandate over pair semantics, so MISCOMPOSED does not block.
        if ($axis !== 'B') {
            ++$red;
        }
    }
}

$observable = 0;
$total = 0;

foreach ($cells as $cell) {
    ++$total;

    if ($cell->verdict !== Verdict::NOT_OBSERVABLE) {
        ++$observable;
    }
}

printf("\n  %-22s %d\n", 'in-process probes', $inProcess->observations());
printf("  %-22s %d\n", 'product runs', $process->runs());
printf("  %-22s %d of %d (%.1f%%)\n", 'NOT OBSERVABLE', $total - $observable, $total, $total === 0 ? 0.0 : ($total - $observable) / $total * 100);
printf("  %-22s %d\n", 'defects (all axes)', $defects);

// A narrowed run cannot judge the floor: it spans all three axes, and a green
// line printed over a partial grid is the shape of false evidence this round
// exists to remove.
$whole = \count($axes) === 3;
// On the LIVE grid the floor is not the floor. Every row this round repaired
// is no longer a defect, so the pre-cure list applied here turned a successful
// cure into a red run. What the live grid is held to instead is the round's
// own claim, row by row: still defective where nothing claims a cure, and no
// longer defective where the `cure` column names one. See `Floor`.
[$misses, $standing, $cured] = $whole
    ? Floor::load($root)->cureMisses($cells)
    : [[], [], []];

printf(
    "  %-22s %s\n",
    'defect floor',
    $whole
        ? \sprintf(
            '%d row(s) still defective, %d cured as declared%s',
            \count($standing),
            \count($cured),
            $misses === [] ? '' : ', ' . \count($misses) . ' row(s) neither',
        )
        : 'not judged (narrowed run)',
);

foreach ($cured as $row) {
    printf("    cured  %s — %s\n", $row->row, $row->cure);
}

foreach ($misses as $miss) {
    fwrite(\STDERR, 'FLOOR: ' . $miss . "\n");
}

foreach ($stand->failures() as $failure) {
    fwrite(\STDERR, 'PROBE FAILED: ' . $failure . "\n");
}

if ($stand->failures() !== []) {
    exit(3);
}

if ($misses !== [] || $spanProblems !== 0) {
    exit(1);
}

exit($red === 0 ? 0 : 1);
