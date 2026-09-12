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
 *   php scripts/promise-effect.php --axis=A,C     narrow the run (never evidence on its own)
 *   php scripts/promise-effect.php --stability    measure twice, demand the same text
 *
 * Exit codes: 0 clean, 1 a red outcome on a BLOCKING axis (which ones those
 * are is declared in `promise-effect/run-declaration.tsv`), 2 a declaration that
 * cannot be read, 3 a probe that could not be taken (see 02 §4: a run that did
 * not confirm its postcondition is not an observation).
 *
 * `--before` carries the DEFECT FLOOR, and that is where the floor belongs: it
 * is a claim about the classifier reading a known pre-cure tree, so it exits 1
 * there when a declared row stops being recognised. The live grid is held to
 * the round's own claim about what it cured instead — see `Floor`.
 */

namespace Qualimetrix\PromiseEffect;

use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Command\Command;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/promise-effect/Ledger.php';
require __DIR__ . '/promise-effect/Declarations.php';
require __DIR__ . '/promise-effect/InProcess.php';
require __DIR__ . '/promise-effect/ProcessProbe.php';
require __DIR__ . '/promise-effect/Classifier.php';
require __DIR__ . '/promise-effect/Limits.php';
require __DIR__ . '/promise-effect/Composition.php';
require __DIR__ . '/promise-effect/Neighbourhood.php';
require __DIR__ . '/promise-effect/Stand.php';
require __DIR__ . '/promise-effect/Floor.php';
require __DIR__ . '/promise-effect/Stamp.php';
require __DIR__ . '/promise-effect/RunDeclaration.php';

/**
 * The one place an axis NAME becomes the cells it produces.
 *
 * Before this the dispatch was three literal `if (in_array('A', ...))` blocks
 * in two code paths, and S11's lesson is exactly that shape: a new axis added
 * to one of them and forgotten in the other is an axis that silently does not
 * get measured. The declared list decides WHICH of these run; this map decides
 * only HOW, and {@see assertAxesHaveGenerators()} holds the two together.
 *
 * @return array<string, callable(Stand): list<Cell>>
 */
function axisGenerators(): array
{
    return [
        'A' => static fn(Stand $stand): array => $stand->axisA(),
        'B' => static fn(Stand $stand): array => $stand->axisB(),
        'C' => static fn(Stand $stand): array => $stand->axisC(),
        'D' => static fn(Stand $stand): array => $stand->axisD(),
        'E' => static fn(Stand $stand): array => $stand->axisE(),
    ];
}

/**
 * Both directions, because both are a way to stop measuring in silence: a
 * declared axis nothing can produce, and a generator no declaration ever
 * names.
 *
 * @param list<string> $declared
 */
function assertAxesHaveGenerators(array $declared): void
{
    $generators = axisGenerators();

    foreach ($declared as $axis) {
        if (!isset($generators[$axis])) {
            throw new LedgerError('run-declaration.tsv names the axis "' . $axis . '", which nothing in this script produces');
        }
    }

    foreach (array_keys($generators) as $axis) {
        if (!\in_array($axis, $declared, true)) {
            throw new LedgerError('the script produces the axis "' . $axis . '", which run-declaration.tsv does not declare');
        }
    }
}

/**
 * @param list<string> $axes
 *
 * @return list<Cell>
 */
function measure(Stand $stand, array $axes): array
{
    $generators = axisGenerators();
    $cells = [];

    foreach ($axes as $axis) {
        $cells = [...$cells, ...$generators[$axis]($stand)];
    }

    return $cells;
}

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

/**
 * The grid as a flat map, which is the second argument the limit guard needs:
 * one of its rules asks whether a SIBLING form of the same row publishes the
 * crash a limit covers.
 *
 * @param list<Cell> $cells
 *
 * @return array<string, string>
 */
function judgedMap(array $cells): array
{
    $map = [];

    foreach ($cells as $cell) {
        $map[$cell->key] = $cell->verdict;
    }

    return $map;
}

/**
 * Which `(door, key)` the product itself writes by REPEATING a flag, read off
 * the check command's own input definition.
 *
 * This is the machine basis under the two door kinds of
 * `observability-limits.tsv`: a flag declared `VALUE_IS_ARRAY` (or an array
 * argument) has a list spelling, so a row claiming the door cannot express one
 * is false. Asked of the product rather than declared here, because a
 * hand-written answer to "can this door carry a list" is the very claim under
 * review.
 *
 * `null` marks a flag the definition does not know at all: that is a third
 * state, not a `false`. A ledger row naming a door the command does not define
 * is a broken reading, and answering `false` would let a
 * `door-cannot-express` row pass on a door nobody looked at.
 *
 * @return array<string, bool|null>
 */
function repeatableDoors(Ledger $ledger, string $root): array
{
    $command = new Command('promise-effect-probe');
    $container = (new ContainerFactory())->create();
    $registry = $container->get(RuleRegistryInterface::class);

    if (!$registry instanceof RuleRegistryInterface) {
        throw new LedgerError('the container did not yield the rule registry the CLI door is built from');
    }

    CheckCommandDefinition::addOptions($command, $registry);
    $definition = $command->getDefinition();
    $flags = [];

    foreach (rootFlagTable($root) as $path => $flag) {
        $flags[$path] = $flag;
    }

    $repeatable = [];

    foreach ($ledger->forms as $row) {
        $name = match ($row->door) {
            'cli-root' => $flags[$row->path] ?? null,
            'cli-alias' => $row->alias === '' ? null : ltrim($row->alias, '-'),
            default => null,
        };

        if ($name === null) {
            continue;
        }

        if ($name === '(positional)') {
            $argument = $definition->hasArgument('paths') ? $definition->getArgument('paths') : null;
            $repeatable[$row->door . '|' . $row->path] = $argument?->isArray();

            continue;
        }

        $option = ltrim($name, '-');
        $repeatable[$row->door . '|' . $row->path] = $definition->hasOption($option)
            ? $definition->getOption($option)->isArray()
            : null;
    }

    return $repeatable;
}

/**
 * The flag each configuration root is written with, from the same declaration
 * the stand probes through.
 *
 * @return array<string, string>
 */
function rootFlagTable(string $root): array
{
    $lines = file($root . '/promise-effect/cli-root-flags.tsv', \FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new LedgerError('cannot read promise-effect/cli-root-flags.tsv');
    }

    $flags = [];
    $header = false;

    foreach ($lines as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!$header) {
            $header = true;

            continue;
        }

        $cells = array_pad(explode("\t", $line), 5, '');
        $flags[$cells[0]] = $cells[1];
    }

    return $flags;
}

/**
 * @param list<Cell> $cells
 *
 * @return list<string>
 */
function limitProblems(Limits $limits, Stand $stand, array $cells, Ledger $ledger, string $root): array
{
    $problems = $limits->conflicts($stand->unrestricted(), judgedMap($cells));
    $repeatable = repeatableDoors($ledger, $root);
    $readPerDoor = [];

    foreach ($repeatable as $cell => $repeats) {
        $door = explode('|', $cell, 2)[0];
        $readPerDoor[$door] = ($readPerDoor[$door] ?? 0) + ($repeats === null ? 0 : 1);

        if ($repeats === null) {
            $problems[] = $cell . ': the ledger names a CLI door the check command does not define, so nothing was read about it';
        }
    }

    // A basis read off an empty definition would agree with anything, so it is
    // asked PER DOOR: one door answering for all of them would leave the other
    // unchecked while the total looked healthy. Beside that, the reading as a
    // whole must distinguish — an answer of one value everywhere is not a
    // reading either.
    foreach ($readPerDoor as $door => $read) {
        if ($read === 0) {
            $problems[] = $door . ': no flag of this door was read against the input definition, so its kind rests on nothing';
        }
    }

    if (!\in_array(true, $repeatable, true) || !\in_array(false, $repeatable, true)) {
        $problems[] = 'the CLI doors yielded no repeatable/non-repeatable pair, so the basis of the door kinds never distinguished anything';
    }

    return [...$problems, ...$limits->basisProblems($repeatable)];
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

$snapshotDirectory = $root . '/' . Stand::SNAPSHOT_DIR;

if (!is_dir($snapshotDirectory)) {
    mkdir($snapshotDirectory, 0o775, true);
}

try {
    // The one declared source for the axis order and the "before" commit —
    // see `RunDeclaration`. `--axis=` narrows this same list; it never
    // supplies a second one of its own.
    $runDeclaration = RunDeclaration::load($root);
    $canonicalAxes = $runDeclaration->axes;
    assertAxesHaveGenerators($canonicalAxes);
    $ledger = Ledger::load($root);
    $declarations = Declarations::load($root);
    $inProcess = new InProcess($scratch);
    $process = new ProcessProbe($root, $scratch);
    $stand = new Stand($root, $ledger, $declarations, $inProcess, $process);
} catch (LedgerError $error) {
    fwrite(\STDERR, 'promise-effect: ' . $error->getMessage() . "\n");

    exit(2);
}

$axes = isset($arguments['axis']) ? explode(',', $arguments['axis']) : $canonicalAxes;

if (isset($arguments['before'])) {
    // Re-judged, never replayed: the frozen file holds raw observations, and
    // today's classifier is applied to them. Editing the classifier therefore
    // moves BOTH halves of the pair, which is the property the freeze exists
    // to keep.
    $frozen = $stand->before(readRaw($snapshotDirectory . '/observations-before/raw.tsv'));

    foreach ($canonicalAxes as $axis) {
        printf("axis %s (before)\n", $axis);

        foreach (summarize($frozen, $axis) as $verdict => $count) {
            printf("  %-22s %d\n", $verdict, $count);
        }
    }

    printf("\n  %-22s %d\n", 'defects', \count(array_filter($frozen, static fn(Cell $cell): bool => $cell->defect)));

    // A limit declared over a working observation is a deleted measurement,
    // and the refusal is the same on both halves — which is why the
    // unrestricted verdict is computed beside every limited one.
    $limitConflicts = limitProblems(Limits::load($root), $stand, $frozen, $ledger, $root);

    foreach ($limitConflicts as $conflict) {
        fwrite(\STDERR, 'LIMIT: ' . $conflict . "\n");
    }

    printf(
        "  %-22s %d cell(s) covered, %d of them over a lawful effect\n",
        'observability limit',
        \count($stand->unrestricted()),
        \count($limitConflicts),
    );

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

    exit($floorMisses === [] && $limitConflicts === [] ? 0 : 1);
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
        $texts[] = render(measure($round, $axes));
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

$cells = measure($stand, $axes);
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
if (\count($axes) === \count($canonicalAxes)) {
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
    $beforeCommit = $runDeclaration->beforeCommit;

    // A declared commit that does not exist in this repository is a run
    // failure here, not a `HEAD is (something else)` message that reads like
    // the branch is merely on the wrong commit — see
    // `RunDeclaration::assertBeforeCommitExists()`.
    try {
        $runDeclaration->assertBeforeCommitExists($root);
    } catch (LedgerError $error) {
        fwrite(\STDERR, 'promise-effect: ' . $error->getMessage() . "\n");

        exit(3);
    }

    // The snapshot stores RAW observations, never verdicts: one classifier
    // judges both halves of the pair, and a frozen verdict would let a later
    // edit of the classifier split them in silence.
    $head = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));

    if (!str_starts_with($head, $beforeCommit)) {
        fwrite(\STDERR, 'promise-effect: the "before" shot must be taken on ' . $beforeCommit . ', HEAD is ' . $head . "\n");

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

foreach ($canonicalAxes as $axis) {
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

        // Only the axes the declaration calls blocking move the exit code.
        // B, C and E are measured and not cured HERE: axis B has no owner
        // holding a mandate over pair semantics, and axis C and the
        // neighbourhood coordinate are this round's own subject — a stage that
        // measures them cannot also demand they already be green, or the grid
        // it exists to produce could never be written.
        if (\in_array($axis, $runDeclaration->blockingAxes, true)) {
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

// Printed apart from the verdict counts because it is a fact about the
// LEDGER, not about the product: a row whose carriers decide nothing cannot be
// contradicted, whatever its cell says happened. 03-grid.md asks for this
// number separately for exactly that reason.
if (\in_array('C', $axes, true)) {
    $silent = 0;

    foreach ($ledger->compositions as $row) {
        if ($row->promised === 'unpromised' || $row->promised === '') {
            ++$silent;
        }
    }

    printf("\n  %-22s %d of %d ledger row(s)\n", 'axis C unpromised', $silent, \count($ledger->compositions));

    // 02-stand.md asks for this per pair rather than as a belief about the
    // declared magnitudes: a pair whose two sides render alike is read
    // NOT OBSERVABLE, never nudged until it differs, so the count of cells
    // that DID tell their sides apart is the honest denominator of axis C.
    $alike = 0;
    $asked = 0;

    foreach ($cells as $cell) {
        if ($cell->axis !== 'C') {
            continue;
        }

        if (str_contains($cell->decidedBy, Classifier::SIDES_ALIKE)) {
            ++$alike;
        }

        if ($cell->verdict !== Verdict::NOT_OBSERVABLE || str_contains($cell->decidedBy, Classifier::SIDES_ALIKE)) {
            ++$asked;
        }
    }

    printf("  %-22s %d of %d cell(s) probed, %d could not\n", 'axis C sides apart', $asked - $alike, $asked, $alike);
}

if (\in_array('E', $axes, true)) {
    printf("  %-22s %d cell(s)\n", 'axis E population', \count((new Neighbourhood($root, $inProcess->optionsClasses))->rows));
}

printf("\n  %-22s %d\n", 'in-process probes', $inProcess->observations());
printf("  %-22s %d\n", 'product runs', $process->runs());
printf("  %-22s %d of %d (%.1f%%)\n", 'NOT OBSERVABLE', $total - $observable, $total, $total === 0 ? 0.0 : ($total - $observable) / $total * 100);
printf("  %-22s %d\n", 'defects (all axes)', $defects);

// A narrowed run cannot judge the floor: it must span every declared axis,
// and a green line printed over a partial grid is the shape of false
// evidence this round exists to remove.
$whole = \count($axes) === \count($canonicalAxes);
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

$limitConflicts = limitProblems(Limits::load($root), $stand, $cells, $ledger, $root);

printf(
    "  %-22s %d cell(s) covered, %d of them over a lawful effect\n",
    'observability limit',
    \count($stand->unrestricted()),
    \count($limitConflicts),
);

foreach ($limitConflicts as $conflict) {
    fwrite(\STDERR, 'LIMIT: ' . $conflict . "\n");
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

if ($misses !== [] || $spanProblems !== 0 || $limitConflicts !== []) {
    exit(1);
}

exit($red === 0 ? 0 : 1);
