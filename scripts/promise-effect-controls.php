<?php

declare(strict_types=1);

/**
 * Controls on the promise-effect oracle itself.
 *
 * A stand that has never gone red has said nothing about whether it can. Each
 * case plants exactly one breakage and must redden **its own** cell and no
 * other: the run compares the whole outcome map against the unplanted
 * baseline, so a blanket breakage fails as loudly as one that does not bite.
 * That symmetry is the point — a control that reddens more than it declared is
 * a blanket control wearing a narrow name, and this tree has been bitten by
 * that before.
 *
 * The outcome compared is `VERDICT|defect`, never the verdict alone. The round
 * exists because a framed refusal of a promised form is a defect wearing an
 * innocent label; a control blind to the defect column would be blind to
 * exactly the class being measured.
 *
 * Nine of the cases recompute over the frozen raw observations rather than
 * measuring again — the snapshot already holds every side the classifier
 * reads, so planting costs milliseconds instead of 150 s of product runs.
 * Four more plant into a copied tree and check the population guard.
 *
 * Usage:
 *   php scripts/promise-effect-controls.php
 *   php scripts/promise-effect-controls.php --only=V1,P2
 *
 * Exit codes: 0 every case bit exactly its own row, 1 a case did not,
 * 2 coverage arithmetic failed, 3 the baseline itself is not the grid.
 */

namespace Qualimetrix\PromiseEffectControls;

use FilesystemIterator;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\PromiseEffect\Cell;
use Qualimetrix\PromiseEffect\Declarations;
use Qualimetrix\PromiseEffect\InProcess;
use Qualimetrix\PromiseEffect\Ledger;
use Qualimetrix\PromiseEffect\LedgerError;
use Qualimetrix\PromiseEffect\Population;
use Qualimetrix\PromiseEffect\ProcessProbe;
use Qualimetrix\PromiseEffect\Stand;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/promise-effect/Ledger.php';
require __DIR__ . '/promise-effect/Declarations.php';
require __DIR__ . '/promise-effect/InProcess.php';
require __DIR__ . '/promise-effect/ProcessProbe.php';
require __DIR__ . '/promise-effect/Classifier.php';
require __DIR__ . '/promise-effect/Stand.php';
require __DIR__ . '/promise-effect/Population.php';
require __DIR__ . '/promise-effect-controls/Cases.php';

/** A working copy of the declarations and the frozen observations, one per case. */
final class Workspace
{
    private readonly string $tree;

    /** @var list<string> */
    private const array DECLARATIONS = [
        'promise-effect/forms.tsv',
        'promise-effect/axis-d-envelopes.tsv',
        'promise-effect/axis-d-observables.tsv',
        'promise-effect/cli-root-flags.tsv',
        'promise-effect/axis-a-hits.tsv',
        'promise-effect/witness-envelopes.tsv',
        'promise-effect/pair-kind-scope.tsv',
        'promise-effect/floor.tsv',
        'docs/internal/plans/promise-effect/measurement/promise-ledger.tsv',
        'docs/internal/plans/promise-effect/measurement/config-paths.tsv',
        'docs/internal/plans/promise-effect/measurement/key-pairs.tsv',
        'docs/internal/generated/promise-effect/verdicts.tsv',
        'docs/internal/generated/promise-effect/observations-before/raw.tsv',
    ];

    public function __construct(private readonly string $root)
    {
        $this->tree = sys_get_temp_dir() . '/qmx-promise-effect-controls-' . getmypid();
    }

    public function checkout(): string
    {
        self::remove($this->tree);

        foreach (self::DECLARATIONS as $relative) {
            $target = $this->tree . '/' . $relative;
            $directory = \dirname($target);

            if (!is_dir($directory)) {
                mkdir($directory, 0o775, true);
            }

            copy($this->root . '/' . $relative, $target);
        }

        return $this->tree;
    }

    public function cleanup(): void
    {
        self::remove($this->tree);
    }

    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

/** Applies one planting to a working copy. */
final class Planter
{
    private const string RAW = 'docs/internal/generated/promise-effect/observations-before/raw.tsv';

    private const string LEDGER = 'docs/internal/plans/promise-effect/measurement/promise-ledger.tsv';

    public function __construct(private readonly string $tree) {}

    public function apply(ControlCase $case): void
    {
        if ($case->raw !== []) {
            $this->plantRaw($case->raw);
        }

        foreach ($case->ledger as [$prefix, $column, $cell]) {
            $this->plantLedger($prefix, $column, $cell);
        }
    }

    /** @param list<array{string, string, string, string}> $edits */
    private function plantRaw(array $edits): void
    {
        $lines = (array) file($this->tree . '/' . self::RAW, \FILE_IGNORE_NEW_LINES);
        $rows = [];

        foreach (\array_slice($lines, 1) as $line) {
            if (!\is_string($line) || $line === '') {
                continue;
            }

            $cells = array_pad(explode("\t", $line, 5), 5, '');
            $rows[$cells[1] . "\0" . $cells[2]] = $cells;
        }

        foreach ($edits as [$key, $side, $field, $value]) {
            $index = $field === 'outcome' ? 3 : 4;
            $resolved = $this->resolve($rows, $key, $value);

            if (!isset($rows[$key . "\0" . $side])) {
                // A cell the frozen half does not carry: this is the
                // UNPROMISED planting, and it needs an axis and an outcome of
                // its own.
                $rows[$key . "\0" . $side] = [str_starts_with($key, 'pair|') ? 'B' : 'A', $key, $side, 'accepted', ''];
            }

            $rows[$key . "\0" . $side][$index] = $resolved;
        }

        $out = [$lines[0] ?? "axis\trow\tside\toutcome\tobservation"];

        foreach ($rows as $cells) {
            $out[] = implode("\t", $cells);
        }

        file_put_contents($this->tree . '/' . self::RAW, implode("\n", $out) . "\n");
    }

    /**
     * `@side` reads that side of the same row, `@side@row` of another one. A
     * planting that hand-wrote the text would be writing a value the product
     * never produced, and the comparison would stop being about the product.
     *
     * @param array<string, list<string>> $rows
     */
    private function resolve(array $rows, string $key, string $value): string
    {
        if (!str_starts_with($value, '@')) {
            return $value;
        }

        $halves = explode('@', substr($value, 1), 2);
        $row = $halves[1] ?? $key;
        $side = $halves[0];

        if (!isset($rows[$row . "\0" . $side])) {
            throw new LedgerError('the planting reads ' . $side . ' of ' . $row . ', which the frozen half does not carry');
        }

        return $rows[$row . "\0" . $side][4];
    }

    private function plantLedger(string $prefix, int $column, string $cell): void
    {
        $path = $this->tree . '/' . self::LEDGER;
        $lines = (array) file($path, \FILE_IGNORE_NEW_LINES);
        $hits = 0;

        foreach ($lines as $index => $line) {
            if (!\is_string($line) || !str_starts_with($line, $prefix)) {
                continue;
            }

            ++$hits;
            $cells = explode("\t", $line);
            $cells[$column] = $cell;
            $lines[$index] = implode("\t", $cells);
        }

        if ($hits !== 1) {
            throw new LedgerError('the ledger planting matched ' . $hits . ' rows, and a control that moves more than one row is a blanket control');
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }
}

/** @return array<string, string> cell key => `VERDICT|defect` */
function outcomes(string $tree, InProcess $inProcess, ProcessProbe $process): array
{
    $stand = new Stand($tree, Ledger::load($tree), Declarations::load($tree), $inProcess, $process);
    $raw = [];
    $lines = (array) file($tree . '/docs/internal/generated/promise-effect/observations-before/raw.tsv', \FILE_IGNORE_NEW_LINES);

    foreach (\array_slice($lines, 1) as $line) {
        if (!\is_string($line) || $line === '') {
            continue;
        }

        /** @var array{string, string, string, string, string} $cells */
        $cells = array_pad(explode("\t", $line, 5), 5, '');
        $raw[] = $cells;
    }

    $map = [];

    foreach ($stand->before($raw) as $cell) {
        $map[$cell->key] = $cell->verdict . '|' . ($cell->defect ? 'yes' : 'no');
    }

    return $map;
}

/** @return array<string, string> producer name => options class */
function runtimeRules(): array
{
    $execution = (new ContainerFactory())->create()->get(RuleExecutionInterface::class);

    if (!$execution instanceof RuleExecutionInterface) {
        throw new LedgerError('the container did not yield the rule execution the product wires');
    }

    $rules = [];

    foreach ($execution->allRules() as $metadata) {
        $rules[$metadata->name] = $metadata->optionsClass;
    }

    return $rules;
}

/** @return list<string> */
function gridKeys(string $tree): array
{
    $keys = [];
    $lines = (array) file($tree . '/docs/internal/generated/promise-effect/verdicts.tsv', \FILE_IGNORE_NEW_LINES);

    foreach (\array_slice($lines, 1) as $line) {
        if (!\is_string($line) || $line === '') {
            continue;
        }

        $keys[] = explode("\t", $line)[1] ?? '';
    }

    return $keys;
}

$root = \dirname(__DIR__);
/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$only = [];

foreach (\array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--only=')) {
        $only = explode(',', substr($argument, \strlen('--only=')));
    }
}

$scratch = sys_get_temp_dir() . '/qmx-promise-effect-controls-scratch';
$inProcess = new InProcess($scratch);
$process = new ProcessProbe($root, $scratch);
$workspace = new Workspace($root);
$rules = runtimeRules();

// The cheap half first, as the sibling stands do: coverage arithmetic before
// any planting. A case whose cell is not in the universe of the run is a stale
// declaration, and that is a different failure from a case that does not bite.
$tree = $workspace->checkout();
$baseline = outcomes($tree, $inProcess, $process);
$stale = [];
$verdictsCovered = [];

foreach (Cases::verdicts() as $case) {
    $verdictsCovered[$case->verdict] = true;

    foreach (array_keys($case->expected) as $key) {
        if ($case->verdict === 'UNPROMISED') {
            // The one verdict with no baseline cell by construction: it fires
            // precisely when the grid grew past the ledger.
            continue;
        }

        if (!isset($baseline[$key])) {
            $stale[] = $case->id . ' expects ' . $key . ', which the run does not carry';
        }
    }
}

foreach (['OK', 'INERT', 'COLLAPSED', 'REFUSES', 'MALFORMED', 'NOT OBSERVABLE', 'UNPROMISED', 'COEXISTENCE_OK', 'MISCOMPOSED'] as $verdict) {
    if (!isset($verdictsCovered[$verdict])) {
        $stale[] = 'no control case plants ' . $verdict;
    }
}

$populations = [];

foreach (Cases::guards() as $guard) {
    $populations[$guard->population] = true;
}

foreach (['producer', 'options-class', 'config-path', 'same-source-pair'] as $population) {
    if (!isset($populations[$population])) {
        $stale[] = 'no guard case plants a new member of the ' . $population . ' population';
    }
}

// The baseline must BE the grid: controls recomputed over a half that no
// longer reproduces the published verdicts would prove something about a
// document nobody reads.
$published = [];
$lines = (array) file($root . '/docs/internal/generated/promise-effect/verdicts.tsv', \FILE_IGNORE_NEW_LINES);

foreach (\array_slice($lines, 1) as $line) {
    if (!\is_string($line) || $line === '') {
        continue;
    }

    $cells = explode("\t", $line);
    $published[$cells[1]] = $cells[4] . '|' . $cells[5];
}

$divergence = 0;

foreach ($published as $key => $outcome) {
    if (($baseline[$key] ?? '(absent)') !== $outcome) {
        ++$divergence;
    }
}

if ($stale !== []) {
    foreach ($stale as $line) {
        fwrite(\STDERR, 'STALE CASE DECLARATION: ' . $line . "\n");
    }

    $workspace->cleanup();

    exit(2);
}

if ($divergence !== 0) {
    fwrite(\STDERR, 'the frozen half no longer reproduces the published grid: ' . $divergence . " cell(s) differ\n");
    $workspace->cleanup();

    exit(3);
}

printf(
    "Coverage arithmetic: %d verdict case(s) over 9 verdicts and %d guard case(s) over 4 populations, in a universe of %d cells.\n",
    \count(Cases::verdicts()),
    \count(Cases::guards()),
    \count($baseline),
);

$failures = [];
$ran = 0;

foreach (Cases::verdicts() as $case) {
    if ($only !== [] && !\in_array($case->id, $only, true)) {
        continue;
    }

    ++$ran;
    $tree = $workspace->checkout();

    try {
        (new Planter($tree))->apply($case);
        $planted = outcomes($tree, $inProcess, $process);
    } catch (LedgerError $error) {
        $failures[] = $case->id . ': the planting broke the loader instead of the stand (' . $error->getMessage() . ')';

        continue;
    }

    $moved = [];

    foreach ($planted as $key => $outcome) {
        if (($baseline[$key] ?? '(absent)') !== $outcome) {
            $moved[$key] = $outcome;
        }
    }

    foreach (array_keys($baseline) as $key) {
        if (!isset($planted[$key])) {
            $moved[$key] = '(gone)';
        }
    }

    foreach ($case->expected as $key => $outcome) {
        if (($moved[$key] ?? null) !== $outcome) {
            $failures[] = \sprintf(
                '%s: expected %s to become %s, it became %s',
                $case->id,
                $key,
                $outcome,
                $moved[$key] ?? ($planted[$key] ?? '(absent)'),
            );
        }

        unset($moved[$key]);
    }

    foreach (array_keys($moved) as $key) {
        $failures[] = $case->id . ': the planting also moved ' . $key . ', a cell it does not name';

        break;
    }

    printf("  %-3s %-15s %s\n", $case->id, $case->verdict, $case->intent);
}

foreach (Cases::guards() as $guard) {
    if ($only !== [] && !\in_array($guard->id, $only, true)) {
        continue;
    }

    ++$ran;
    $tree = $workspace->checkout();
    [$relative, $content] = $guard->file;
    $target = $tree . '/' . $relative;

    if (!is_dir(\dirname($target))) {
        mkdir(\dirname($target), 0o775, true);
    }

    is_file($target)
        ? file_put_contents($target, $content, \FILE_APPEND)
        : file_put_contents($target, $content);

    $uncovered = (new Population($tree, $rules))->uncovered(gridKeys($tree));
    $named = false;

    foreach ($uncovered as $line) {
        if (str_starts_with($line, $guard->expected . ' ')) {
            $named = true;
        }
    }

    if (!$named) {
        $failures[] = $guard->id . ': the guard did not name ' . $guard->expected . ' as uncovered; it named ' . ($uncovered === [] ? 'nothing' : implode('; ', $uncovered));
    }

    if (\count($uncovered) > 1) {
        $failures[] = $guard->id . ': the guard named ' . \count($uncovered) . ' uncovered members, and a guard that reddens more than the planting is a blanket guard';
    }

    printf("  %-3s %-15s %s\n", $guard->id, $guard->population, $guard->intent);
}

$workspace->cleanup();

foreach ($failures as $failure) {
    fwrite(\STDERR, $failure . "\n");
}

printf("Ran %d control cases, %d failures.\n", $ran, \count($failures));

exit($failures === [] ? 0 : 1);
