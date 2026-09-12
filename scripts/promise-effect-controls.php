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
 * Ten of the cases recompute over the frozen raw observations rather than
 * measuring again — the snapshot already holds every side the classifier
 * reads, so planting costs milliseconds instead of 150 s of product runs.
 * Four more plant into a copied tree and check the population guard.
 *
 * Three further groups exist because that rule is not the whole stand. The
 * PROBE cases address what happens before an observation is stored, which the
 * frozen half is blind to by construction, and each holds its own assertions
 * rather than a cell map. The CROSS-CHECK cases plant into each side of the
 * four sets — the registry in a copied file, the declaration in memory,
 * because a copied tree resolves PSR-4 back into the original `src/` — and one
 * of them asserts a property of the UNPLANTED comparison, since a comparison
 * that ignored the door normalization would move the same way under any
 * planting while being wrong everywhere.
 *
 * Usage:
 *   php scripts/promise-effect-controls.php
 *   php scripts/promise-effect-controls.php --only=V1,P2
 *
 * Exit codes: 0 every case bit exactly its own row, 1 a case did not,
 * 2 coverage arithmetic failed. Exit 3 was "the baseline itself is not the
 * grid" and is retired: the published grid measures the cured tree and the
 * frozen half the pre-cure one, so they are SUPPOSED to differ now. See the
 * note where it stood.
 */

namespace Qualimetrix\PromiseEffectControls;

use FilesystemIterator;
use PromiseEffectP1Set;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\PromiseEffect\Cell;
use Qualimetrix\PromiseEffect\Classifier;
use Qualimetrix\PromiseEffect\CompositionComparison;
use Qualimetrix\PromiseEffect\CompositionPlan;
use Qualimetrix\PromiseEffect\CompositionPlanner;
use Qualimetrix\PromiseEffect\CrossCheck;
use Qualimetrix\PromiseEffect\Declarations;
use Qualimetrix\PromiseEffect\Floor;
use Qualimetrix\PromiseEffect\InProcess;
use Qualimetrix\PromiseEffect\KeyPairGroups;
use Qualimetrix\PromiseEffect\Ledger;
use Qualimetrix\PromiseEffect\LedgerError;
use Qualimetrix\PromiseEffect\Limits;
use Qualimetrix\PromiseEffect\Observation;
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
require __DIR__ . '/promise-effect/Limits.php';
require __DIR__ . '/promise-effect/Composition.php';
require __DIR__ . '/promise-effect/Neighbourhood.php';
require __DIR__ . '/promise-effect/Stand.php';
require __DIR__ . '/promise-effect/Population.php';
require __DIR__ . '/promise-effect/FifthSet.php';
require __DIR__ . '/promise-effect/CrossCheck.php';
require __DIR__ . '/promise-effect/Floor.php';
require __DIR__ . '/promise-effect-controls/Cases.php';
require __DIR__ . '/promise-effect-p1-set.php';

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
        'promise-effect/composition-magnitudes.tsv',
        'promise-effect/door-normalization.tsv',
        'promise-effect/observability-limits.tsv',
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

/**
 * The stand the frozen half is read through, kept beside {@see frozenCells()}
 * because a limit conflict is read off the STAND and not off its cells.
 */
function frozenStand(string $tree, InProcess $inProcess, ProcessProbe $process): Stand
{
    $stand = new Stand($tree, Ledger::load($tree), Declarations::load($tree), $inProcess, $process, Limits::load($tree));
    $stand->before(frozenRaw($tree));

    return $stand;
}

/**
 * The limit guard as the run asks it: the covered cells and the whole judged
 * grid, because one of its rules is about a SIBLING form of the same row.
 *
 * @return list<string>
 */
function limitConflicts(string $tree, InProcess $inProcess, ProcessProbe $process): array
{
    $stand = frozenStand($tree, $inProcess, $process);
    $judged = [];

    foreach (frozenCells($tree, $inProcess, $process) as $cell) {
        $judged[$cell->key] = $cell->verdict;
    }

    return Limits::load($tree)->conflicts($stand->unrestricted(), $judged);
}

/**
 * A synthetic cell for one floor row, as the row itself declares it should
 * read on the live grid: still defective when nothing claims otherwise, not a
 * defect where a `cure` does, and NOT OBSERVABLE for the declared reason where
 * a `withdrawn` does. The three dispositions have to be synthesized apart, or
 * a case would be planting a breakage while calling it a baseline.
 */
function floorCellAsDeclared(\Qualimetrix\PromiseEffect\FloorRow $row): Cell
{
    if ($row->declaredWithdrawn()) {
        return new Cell('A', $row->row, '-', 'optionsObject', 'NOT OBSERVABLE', $row->withdrawalEvidence(), 'DECIDED');
    }

    return new Cell('A', $row->row, '-', 'optionsObject', floorVerdict($row->verdict), 'planted', 'DECIDED', !$row->declaredCured());
}

/** The verdict a synthetic floor cell carries: the declared one, or any defect label. */
function floorVerdict(string $declared): string
{
    return $declared === 'any-defect' ? 'INERT' : $declared;
}

/**
 * The cells the frozen half yields for a tree — the same reading the verdict
 * cases compare, handed back as cells so the floor can be asked about them.
 *
 * @return list<Cell>
 */
function frozenCells(string $tree, InProcess $inProcess, ProcessProbe $process): array
{
    $stand = new Stand($tree, Ledger::load($tree), Declarations::load($tree), $inProcess, $process, Limits::load($tree));

    return $stand->before(frozenRaw($tree));
}

/** @return list<array{string, string, string, string, string}> */
function frozenRaw(string $tree): array
{
    $raw = [];

    foreach (\array_slice((array) file($tree . '/docs/internal/generated/promise-effect/observations-before/raw.tsv', \FILE_IGNORE_NEW_LINES), 1) as $line) {
        if (!\is_string($line) || $line === '') {
            continue;
        }

        /** @var array{string, string, string, string, string} $cells */
        $cells = array_pad(explode("\t", $line, 5), 5, '');
        $raw[] = $cells;
    }

    return $raw;
}

/**
 * The four sets as a flat map, so a planting can be compared cell for cell
 * the way a verdict planting is: bucket and cell key, never the prose.
 *
 * @param array<string, string> $rules
 * @param array{string, string, string}|null $shape
 *
 * @return array<string, true>
 */
function crossCheckLines(string $tree, array $rules, ?array $shape = null): array
{
    $check = new CrossCheck($tree, $rules, Ledger::load($tree));

    if ($shape !== null) {
        [$rule, $key, $factory] = $shape;
        // Named one by one rather than called dynamically: a case naming a
        // factory that does not exist must be a stale declaration, not a
        // fatal, and the vocabulary a control may plant is small on purpose.
        $planted = match ($factory) {
            'integer' => RuleOptionShape::integer(),
            'boolean' => RuleOptionShape::boolean(),
            'text' => RuleOptionShape::text(),
            default => throw new LedgerError('no control may plant the shape "' . $factory . '"'),
        };
        $check->plantShape($rule, $key, $planted->orNull());
    }

    $report = $check->compute();
    $lines = [];

    foreach ([
        'WIDER' => $report->wider,
        'WIDER_UNOPPOSED' => $report->widerUnopposed,
        'LEDGER_ONLY' => $report->ledgerOnly,
        'DEEPER' => $report->deeper,
        'UNDECLARED' => $report->undeclaredKeys,
        'FORMLESS' => $report->formless,
        'ANSWERED' => $report->answeredByTheClass,
        'UNOWNED' => $report->unowned,
    ] as $bucket => $bucketLines) {
        foreach ($bucketLines as $line) {
            $lines[$bucket . '|' . explode(': ', $line, 2)[0]] = true;
        }
    }

    return $lines;
}

/**
 * The two probe controls, each holding its own assertions.
 *
 * B1 takes the real process pair — there is no other way to say "the fixture
 * still answers unframed TODAY" — and then plants an outcome into the pure
 * judgement, which is what makes the control a control rather than a report.
 *
 * B2 addresses the logfile extraction directly. It has to: the frozen half
 * stores an md5 of the contaminated line, and nothing recomputed over the
 * snapshot can reach what that digest ate.
 *
 * @return list<string> what went wrong, empty when the case holds
 */
function runProbeCase(ProbeCase $case, string $root, InProcess $inProcess, ProcessProbe $process): array
{
    if ($case->id === 'B1') {
        $stand = new Stand($root, Ledger::load($root), Declarations::load($root), $inProcess, $process);
        $problems = $stand->proveRefusalFraming();
        $failures = $problems === []
            ? []
            : ['B1: the framing control does not hold on today\'s tree: ' . implode('; ', $problems)];

        // One planting per side, and each must name its own side and only it.
        $framedBroken = Stand::framingProblems('accepted', 'planted', 'refused-unframed', 'x');

        if (\count($framedBroken) !== 1 || !str_contains($framedBroken[0], 'ConfigurationRefusal')) {
            $failures[] = 'B1: breaking the framed side did not produce exactly its own problem';
        }

        $unframedBroken = Stand::framingProblems('refused-framed', 'x', 'refused-framed', 'planted');

        if (\count($unframedBroken) !== 1 || !str_contains($unframedBroken[0], 'unframed refusal control')) {
            $failures[] = 'B1: breaking the unframed side did not produce exactly its own problem';
        }

        return $failures;
    }

    if ($case->id === 'PL1') {
        // The one decision of axis C that no fixture case can reach: whether
        // the sibling question is asked at all. A plan that answered `true`
        // everywhere would make every triple read as a correct composition,
        // and a plan answering `false` everywhere would make every kept
        // promise on a list-shaped key a LOST_SIBLING — the two mistakes this
        // flag stands between, measured once each.
        $planner = new CompositionPlanner(
            Declarations::load($root),
            $inProcess->aliases,
            new KeyPairGroups($root),
        );
        $failures = [];
        $expected = ['composition-path' => true, 'composition-triple' => false];

        foreach ($expected as $kind => $answer) {
            $seen = false;

            foreach (Ledger::load($root)->compositions as $row) {
                if ($row->kind !== $kind) {
                    continue;
                }

                $rule = $kind === 'composition-path' ? substr($row->subject, \strlen('rules.')) : $row->subject;
                $option = '';

                // The producer name is the longest registered prefix of the
                // path, exactly as the grid splits it.
                foreach (array_keys($inProcess->optionsClasses) as $producer) {
                    if (str_starts_with($rule . '.', $producer . '.')) {
                        $option = substr($rule, \strlen($producer) + 1);
                        $rule = $producer;
                    }
                }

                $plan = $planner->plan($row, $rule, $option, CompositionPlanner::POINT_OBJECT);

                if (!$plan instanceof CompositionPlan) {
                    continue;
                }

                $seen = true;

                if ($plan->highRewritesEveryLowKey() !== $answer) {
                    $failures[] = 'PL1: the plan for ' . $row->key() . ' answers '
                        . ($plan->highRewritesEveryLowKey() ? 'true' : 'false') . ', and a ' . $kind . ' row owes '
                        . ($answer ? 'true' : 'false');
                }
            }

            if (!$seen) {
                $failures[] = 'PL1: the ledger carries no plannable ' . $kind . ' row, so this half of the case measured nothing';
            }
        }

        return $failures;
    }

    if ($case->id === 'N1' || $case->id === 'N2') {
        $workspace = new Workspace($root);
        $tree = $workspace->checkout();
        // A cell on a CLI door whose form that door has no spelling for: the
        // measured pair is `warning=7331` (exit 0) against `warning="7331"`
        // (exit 3), so a verdict here would be about the quoting and not about
        // the form.
        $covered = 'form|rule-opt|rules.complexity.ccn.callable.warning|string-number';
        // A cell whose effect the stand DOES observe, and which therefore no
        // limit may cover.
        $lawful = 'form|yaml|rules.coupling.class-rank.error|float';
        $cells = frozenCells($tree, $inProcess, $process);
        $failures = [];
        $verdicts = [];

        foreach ($cells as $cell) {
            $verdicts[$cell->key] = $cell->verdict;
        }

        if ($case->id === 'N1') {
            if (($verdicts[$covered] ?? '(absent)') !== 'NOT OBSERVABLE') {
                $failures[] = 'N1: ' . $covered . ' is covered by a declared limit and the run judged it anyway: '
                    . ($verdicts[$covered] ?? '(absent)');
            }

            // The same cell without the limit must NOT be NOT OBSERVABLE, or
            // the case would pass against a stand that never read the table.
            $unrestricted = [];

            foreach (frozenStand($tree, $inProcess, $process)->unrestricted() as [$key, $verdict]) {
                $unrestricted[$key] = $verdict;
            }

            if (($unrestricted[$covered] ?? 'NOT OBSERVABLE') === 'NOT OBSERVABLE') {
                $failures[] = 'N1: without the limit ' . $covered . ' would read NOT OBSERVABLE anyway, so the case proves nothing';
            }

            $workspace->cleanup();

            return $failures;
        }

        if (limitConflicts($tree, $inProcess, $process) !== []) {
            $failures[] = 'N2: the declared table already conflicts with the stand before anything is planted';
        }

        // Planted: a limit on the YAML door, which expresses every form, over
        // a cell that reads OK.
        file_put_contents(
            $tree . '/promise-effect/observability-limits.tsv',
            "door-cannot-express\tyaml\terror\tfloat\tplanted\n",
            \FILE_APPEND,
        );

        $conflicts = limitConflicts($tree, $inProcess, $process);
        $named = false;

        foreach ($conflicts as $conflict) {
            $named = $named || str_starts_with($conflict, $lawful . ':');
        }

        if (!$named) {
            $failures[] = 'N2: a limit planted over ' . $lawful . ' did not redden; the run said '
                . ($conflicts === [] ? 'nothing' : implode('; ', $conflicts));
        }

        $workspace->cleanup();

        return $failures;
    }

    if ($case->id === 'F3') {
        // The third disposition of the live floor, planted four ways. Three of
        // them are the ways a withdrawal can be false; the fourth is the hole
        // the column would open if it were only ever asked about itself — a
        // row leaving the floor with NO disposition must still redden.
        $floor = Floor::load($root);
        $failures = [];
        $subject = null;
        $undeclared = null;

        foreach ($floor->rows as $row) {
            if ($subject === null && $row->declaredWithdrawn()) {
                $subject = $row;
            }

            if ($undeclared === null && !$row->declaredWithdrawn() && !$row->declaredCured()) {
                $undeclared = $row;
            }
        }

        if ($subject === null || $undeclared === null) {
            return ['F3: the floor declares no withdrawn row, or no undeclared one, so this case has nothing to plant into'];
        }

        /** @param callable(\Qualimetrix\PromiseEffect\FloorRow): ?Cell $plant */
        $missesWith = static function (callable $plant) use ($floor): array {
            $cells = [];

            foreach ($floor->rows as $row) {
                $cells[] = $plant($row) ?? floorCellAsDeclared($row);
            }

            [$misses] = $floor->cureMisses($cells);

            return $misses;
        };

        if ($missesWith(static fn(): ?Cell => null) !== []) {
            $failures[] = 'F3: the floor as every row declares itself is not what the rule reads';
        }

        // Each planting breaks ONE of the three demands and satisfies the other
        // two — the reason travels with the cell even where it is irrelevant.
        // Planted any other way, a single rule would catch every case and the
        // case would prove the guard has one rule rather than three.
        $plantings = [
            'still defective' => static fn(\Qualimetrix\PromiseEffect\FloorRow $row): ?Cell => $row === $subject
                ? new Cell('A', $row->row, '-', 'optionsObject', 'NOT OBSERVABLE', $row->withdrawalEvidence(), 'DECIDED', true)
                : null,
            'observed after all' => static fn(\Qualimetrix\PromiseEffect\FloorRow $row): ?Cell => $row === $subject
                ? new Cell('A', $row->row, '-', 'optionsObject', 'OK', $row->withdrawalEvidence(), 'DECIDED')
                : null,
            'blind for another reason' => static fn(\Qualimetrix\PromiseEffect\FloorRow $row): ?Cell => $row === $subject
                ? new Cell('A', $row->row, '-', 'optionsObject', 'NOT OBSERVABLE', 'no reachability witness for the producer', 'DECIDED')
                : null,
            'undeclared row left the floor' => static fn(\Qualimetrix\PromiseEffect\FloorRow $row): ?Cell => $row === $undeclared
                ? new Cell('A', $row->row, '-', 'optionsObject', 'NOT OBSERVABLE', 'planted', 'DECIDED')
                : null,
        ];

        // The declaration itself: a row cannot be both repaired and unmeasured,
        // and the file is refused rather than silently preferring one column.
        $workspace = new Workspace($root);
        $tree = $workspace->checkout();
        file_put_contents(
            $tree . '/promise-effect/floor.tsv',
            "form|yaml|planted|bool\tany-defect\tplanted\tplanted cure\tplanted reason\n",
            \FILE_APPEND,
        );

        try {
            Floor::load($tree);
            $failures[] = 'F3: a row declared both cured and withdrawn was read without complaint';
        } catch (LedgerError $error) {
            if (!str_contains($error->getMessage(), 'both cured and withdrawn')) {
                $failures[] = 'F3: the refusal of a double declaration says something else: ' . $error->getMessage();
            }
        }

        $workspace->cleanup();

        foreach ($plantings as $what => $plant) {
            $expected = $what === 'undeclared row left the floor' ? $undeclared->row : $subject->row;
            $misses = $missesWith($plant);

            if (\count($misses) !== 1 || !str_starts_with($misses[0], $expected . ':')) {
                $failures[] = 'F3: planting "' . $what . '" should have left exactly one miss naming ' . $expected
                    . '; it left ' . ($misses === [] ? 'none' : implode('; ', $misses));
            }
        }

        return $failures;
    }

    if ($case->id === 'N3' || $case->id === 'N4') {
        $workspace = new Workspace($root);
        $tree = $workspace->checkout();
        $failures = limitConflicts($tree, $inProcess, $process) === []
            ? []
            : [$case->id . ': the declared table already conflicts with the stand before anything is planted'];

        if ($case->id === 'N3') {
            // A covered cell whose row publishes no crash anywhere: its
            // uncovered forms read OK, INERT and OK. Turning this one into an
            // unframed refusal is therefore a crash the report would carry
            // nowhere else, and the limit would be the only thing between it
            // and the grid.
            $crash = 'form|yaml|rules.annotation.directive.suppress-paths|int';
            $published = 'form|yaml|rules.annotation.directive.suppress-paths|bool';
            (new Planter($tree))->apply(new ControlCase('plant', '', '', [[$crash, 'value', 'outcome', 'crashed']], [], []));
            $conflicts = limitConflicts($tree, $inProcess, $process);

            if (!conflictsName($conflicts, $crash)) {
                $failures[] = 'N3: a crash under a limit its row publishes nowhere did not redden; the run said '
                    . ($conflicts === [] ? 'nothing' : implode('; ', $conflicts));
            }

            // The other half of the same rule: with the crash ALSO on an
            // uncovered form of that row, the limit hides nothing and the
            // exception must hold. Without this the case would pass against a
            // guard that simply refused every crash.
            (new Planter($tree))->apply(new ControlCase('plant', '', '', [[$published, 'value', 'outcome', 'crashed']], [], []));
            $tolerated = limitConflicts($tree, $inProcess, $process);

            if (conflictsName($tolerated, $crash)) {
                $failures[] = 'N3: the crash stayed a conflict although an uncovered form of the same row now publishes it: ' . implode('; ', $tolerated);
            }

            $workspace->cleanup();

            return $failures;
        }

        // N4: a value-domain limit planted over a cell the product coerced.
        $coerced = 'form|yaml|rules.code-smell.boolean-argument.allowed-prefixes|map';
        file_put_contents(
            $tree . '/promise-effect/observability-limits.tsv',
            "generic-write-names-nothing\tyaml\tallowed-prefixes\tmap\tplanted\n",
            \FILE_APPEND,
        );
        $conflicts = limitConflicts($tree, $inProcess, $process);

        if (!conflictsName($conflicts, $coerced)) {
            $failures[] = 'N4: a value-domain limit planted over ' . $coerced . ' did not redden; the run said '
                . ($conflicts === [] ? 'nothing' : implode('; ', $conflicts));
        }

        $workspace->cleanup();

        return $failures;
    }

    if ($case->id === 'N5') {
        // Planted into the pure judgement: the basis is a reading of the
        // product's input definition, and what this case is about is the rule
        // applied to that reading, in both directions.
        $limits = Limits::load($root);
        $failures = [];
        $false = $limits->basisProblems(['cli-root|exclude' => false]);

        if (\count($false) !== 1 || !str_contains($false[0], 'no array-valued flag')) {
            $failures[] = 'N5: `' . \Qualimetrix\PromiseEffect\LimitRow::STAND_WRITES_ONE
                . '` declared where the door repeats nothing did not redden; the run said '
                . ($false === [] ? 'nothing' : implode('; ', $false));
        }

        $true = $limits->basisProblems(['cli-root|format' => true]);

        if (\count($true) !== 1 || !str_contains($true[0], 'array-valued')) {
            $failures[] = 'N5: `' . \Qualimetrix\PromiseEffect\LimitRow::DOOR_CANNOT_EXPRESS
                . '` declared over an array-valued flag did not redden; the run said '
                . ($true === [] ? 'nothing' : implode('; ', $true));
        }

        // And the declared table, read against itself: every row the basis
        // does judge must already agree, or the two directions above are being
        // proved on a table that is wrong.
        $agreed = $limits->basisProblems(['cli-root|exclude' => true, 'cli-root|format' => false]);

        if ($agreed !== []) {
            $failures[] = 'N5: the declared table disagrees with the door definition it is judged against: ' . implode('; ', $agreed);
        }

        // A flag the definition does not know is a third state, and it must not
        // be read as "does not repeat": a limit passing because nobody could
        // look is the failure this whole guard is about. The caller reports it;
        // what this asserts is that the basis stays silent rather than agreeing.
        if ($limits->basisProblems(['cli-root|exclude' => null]) !== []) {
            $failures[] = 'N5: an unknown flag was judged by the basis instead of being left to the caller as unread';
        }

        return $failures;
    }

    if ($case->id === 'S1') {
        // Planted into the pure judgement, the way B1 plants into the framing
        // rule: the constants themselves cannot be edited from here, so the
        // control hands the same function a group of its own and demands that
        // exactly its own path comes back — and, beside it, that the real
        // constants name nothing missing on today's tree.
        $failures = [];
        $real = PromiseEffectP1Set::missingDeclaredPaths(PromiseEffectP1Set::declaredPathGroups(), $root . '/');

        if ($real !== []) {
            $failures[] = 'S1: the declared constants name a path no file stands at: ' . implode('; ', $real);
        }

        $planted = PromiseEffectP1Set::missingDeclaredPaths(
            [
                'PLANTED' => [
                    'src/Analysis/Configuration/YamlConfigLoader.php',
                    'src/Analysis/Finding/Contract/Rule/RuleOptionKeySet.php',
                ],
            ],
            $root . '/',
        );

        if (\count($planted) !== 1 || !str_contains($planted[0], 'src/Analysis/Configuration/YamlConfigLoader.php')) {
            $failures[] = 'S1: a group naming one absent path and one real file should have named exactly the absent one; it named '
                . ($planted === [] ? 'nothing' : implode('; ', $planted));
        }

        return $failures;
    }

    if ($case->id === 'F1') {
        // Planted into the RAW observations, so the classifier itself produces
        // the change: `bool` is not a promised form on that row, and a framed
        // refusal of an unpromised form is not a defect — which is exactly the
        // shape of a classifier that has stopped recognising the floor.
        $workspace = new Workspace($root);
        $tree = $workspace->checkout();
        $floorRow = 'form|yaml|rules.complexity.ccn.callable.warning|bool';
        $floor = Floor::load($tree);
        $failures = $floor->missesOnTheFrozenHalf(frozenCells($tree, $inProcess, $process)) === []
            ? []
            : ['F1: the floor does not reproduce on the unplanted pre-cure half'];

        (new Planter($tree))->apply(new ControlCase(
            'plant',
            '',
            '',
            [[$floorRow, 'value', 'outcome', 'refused-framed']],
            [],
            [],
        ));

        $misses = $floor->missesOnTheFrozenHalf(frozenCells($tree, $inProcess, $process));

        if (\count($misses) !== 1 || !str_starts_with($misses[0], $floorRow . ':')) {
            $failures[] = 'F1: the planting should have left exactly one floor miss naming ' . $floorRow
                . '; it left ' . ($misses === [] ? 'none' : implode('; ', $misses));
        }

        $workspace->cleanup();

        return $failures;
    }

    if ($case->id === 'F2') {
        // Synthetic cells, not the published grid: this case is about the
        // RULE, and a control reading the grid would go red whenever the grid
        // does, which is the run's job and not a control's.
        $floor = Floor::load($root);
        $failures = [];
        $cells = [];

        foreach ($floor->rows as $row) {
            // A floor row that names its verdict is synthesized with THAT
            // verdict: one of them asks for MISCOMPOSED, and a case that gave
            // every row the same label would fail for its own reason.
            $cells[] = floorCellAsDeclared($row);
        }

        [$misses] = $floor->cureMisses($cells);

        if ($misses !== []) {
            $failures[] = 'F2: the declared shape of the floor is not the one the rule reads: ' . implode('; ', $misses);
        }

        foreach (['declaredCured' => true, 'standing' => false] as $side => $cured) {
            $planted = [];
            $moved = null;

            foreach ($floor->rows as $row) {
                if ($moved === null && !$row->declaredWithdrawn() && $row->declaredCured() === $cured) {
                    $moved = $row->row;
                    // The two ways a floor row can be wrong on the live grid:
                    // one declared cured that never moved (still its declared
                    // verdict, still a defect), and one standing row that left
                    // the floor with nothing claiming a cure.
                    $planted[] = $cured
                        ? new Cell('A', $row->row, '-', 'optionsObject', floorVerdict($row->verdict), 'planted', 'DECIDED', true)
                        : new Cell('A', $row->row, '-', 'optionsObject', 'OK', 'planted', 'DECIDED', false);

                    continue;
                }

                $planted[] = floorCellAsDeclared($row);
            }

            [$plantedMisses] = $floor->cureMisses($planted);

            if (\count($plantedMisses) !== 1 || $moved === null || !str_starts_with($plantedMisses[0], $moved . ':')) {
                $failures[] = 'F2: flipping the ' . $side . ' side should have left exactly one miss naming ' . ($moved ?? '(no such row)')
                    . '; it left ' . ($plantedMisses === [] ? 'none' : implode('; ', $plantedMisses));
            }
        }

        return $failures;
    }

    $directory = sys_get_temp_dir() . '/qmx-promise-effect-probe-' . getmypid();

    if (!is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    $record = static fn(string $stamp, string $where, int $workers): string => json_encode([
        'timestamp' => $stamp,
        'level' => 'debug',
        'message' => 'StrategySelector: selecting strategy',
        'context' => ['requestedWorkers' => null, 'projectRoot' => $where],
    ], \JSON_UNESCAPED_SLASHES) . "\n" . json_encode([
        'timestamp' => $stamp,
        'level' => 'info',
        'message' => 'StrategySelector: using parallel strategy',
        'context' => ['workers' => $workers, 'projectRoot' => $where, 'cacheEnabled' => true],
    ], \JSON_UNESCAPED_SLASHES) . "\n";

    $first = $directory . '/one.log';
    $second = $directory . '/two.log';
    $third = $directory . '/three.log';
    file_put_contents($first, $record('2026-09-11T09:00:00+00:00', '/tmp/run/aaaa', 14));
    file_put_contents($second, $record('2026-09-11T09:00:01+00:00', '/tmp/run/bbbb', 14));
    file_put_contents($third, $record('2026-09-11T09:00:01+00:00', '/tmp/run/bbbb', 7331));

    $failures = [];
    $one = ProcessProbe::workerDecision($first);
    $two = ProcessProbe::workerDecision($second);
    $three = ProcessProbe::workerDecision($third);

    if ($one !== $two) {
        // The defect this package removed: the timestamp and the run directory
        // travelled into the observation, and the run directory is keyed on
        // the document, so every probe differed from every other one by
        // construction.
        $failures[] = 'B2: two runs of the same decision differ — the observation carries the run, not the decision';
    }

    if ($one === $three) {
        $failures[] = 'B2: a different worker count did not change the observation';
    }

    if (!str_contains($one, 'workers=14') || !str_contains($three, 'workers=7331')) {
        // Without this an extraction that returned a constant would pass the
        // two comparisons above and observe nothing at all.
        $failures[] = 'B2: the observation does not carry the worker number it is supposed to be about';
    }

    Workspace::remove($directory);

    return $failures;
}

/**
 * One axis-C or axis-E case: the fixture read twice, once as declared and once
 * with a single observation replaced.
 *
 * The demand is symmetric with the verdict cases even though nothing is
 * planted into a file: the baseline must read what it declares, the planting
 * must read what IT declares, and the two must differ — a case whose planting
 * moved nothing would otherwise agree with itself and pass.
 *
 * @return list<string> what went wrong, empty when the case holds
 */
function runJudgementCase(JudgementCase $case): array
{
    $failures = [];
    $baseline = judge($case, $case->sides, CompositionComparison::Leaves);

    if ($baseline->verdict !== $case->baselineVerdict) {
        $failures[] = $case->id . ': the unplanted fixture reads ' . $baseline->verdict . ', and the case declares ' . $case->baselineVerdict;
    }

    if ($case->baselineVerdict === $case->verdict) {
        $failures[] = $case->id . ': the planting and the baseline declare the same verdict, so the case cannot show anything moving';
    }

    [$side, $replacement] = $case->planted;
    $planted = [...$case->sides, $side => $replacement];
    $judgement = judge($case, $planted, CompositionComparison::Leaves);

    if ($judgement->verdict !== $case->verdict) {
        $failures[] = $case->id . ': expected the planting to read ' . $case->verdict . ', it read ' . $judgement->verdict;
    }

    if ($judgement->defect !== $case->defect) {
        $failures[] = $case->id . ': expected defect=' . ($case->defect ? 'yes' : 'no') . ', the run said ' . ($judgement->defect ? 'yes' : 'no');
    }

    foreach ($case->substitutions as $name => $expected) {
        $comparison = match ($name) {
            'WholeText' => CompositionComparison::WholeText,
            'WinnerBeforeSibling' => CompositionComparison::WinnerBeforeSibling,
            default => null,
        };

        if ($comparison === null) {
            $failures[] = $case->id . ': no comparison named "' . $name . '" exists to substitute';

            continue;
        }

        $substituted = judge($case, $planted, $comparison);

        if ($substituted->verdict !== $expected) {
            $failures[] = \sprintf(
                '%s: the %s comparison should have renamed this cell into %s, it read %s',
                $case->id,
                $name,
                $expected,
                $substituted->verdict,
            );
        }

        if ($substituted->verdict === $judgement->verdict) {
            $failures[] = $case->id . ': the ' . $name . ' comparison agrees with the real one here, so this case proves no substitution is possible';
        }
    }

    return $failures;
}

/** @param array<string, string> $sides */
function judge(JudgementCase $case, array $sides, CompositionComparison $how): \Qualimetrix\PromiseEffect\Judgement
{
    if ($case->axis === 'E') {
        return Classifier::neighbourhood(
            observationOf($sides['omitted']),
            observationOf($sides['neighbour']),
            observationOf($sides['nullAlone']),
            observationOf($sides['both']),
        );
    }

    return Classifier::composition(
        observationOf($sides['omitted']),
        observationOf($sides['low']),
        observationOf($sides['high']),
        observationOf($sides['both']),
        $case->promised,
        $case->highRewritesEveryLowKey,
        $how,
    );
}

/**
 * A fixture side as the classifier reads one: `outcome|text`, or a bare text
 * for the accepted case. Read through `ofMeasured()`, which is the ONE place a
 * raw outcome becomes a judgeable one — a case that built an Observation
 * directly would be judged by a rule the run does not use.
 */
function observationOf(string $declared): Observation
{
    $halves = explode('|', $declared, 2);

    return \count($halves) === 2 && !str_starts_with($declared, '{')
        ? Observation::ofMeasured($halves[0], $halves[1])
        : Observation::ofMeasured(Observation::ACCEPTED, $declared);
}

/**
 * Whether a guard's report names this cell.
 *
 * @param list<string> $conflicts
 */
function conflictsName(array $conflicts, string $key): bool
{
    foreach ($conflicts as $conflict) {
        if (str_starts_with($conflict, $key . ':')) {
            return true;
        }
    }

    return false;
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

// The new axes get their own coverage arithmetic rather than joining the list
// above: their cases are not plantings into the frozen half, which has no side
// for them to edit, so a stale-declaration check over `$baseline` would refuse
// every one of them.
$judgementCovered = [];

foreach (Cases::judgements() as $judgementCase) {
    $judgementCovered[$judgementCase->verdict] = true;
    $judgementCovered[$judgementCase->baselineVerdict] = true;
}

foreach ([
    'COMPOSED_AS_PROMISED', 'MISLAYERED', 'LOST_SIBLING', 'COMPOSITION_REFUSED', 'FRANKENSTEIN',
    'PRESENCE_NEUTRAL', 'PRESENCE_SWITCHED_BRANCH', 'PRESENCE_REFUSED',
] as $verdict) {
    if (!isset($judgementCovered[$verdict])) {
        $stale[] = 'no judgement case reads ' . $verdict;
    }
}

// Every case id, across every group, must be unique: `--only=` addresses a
// case by id, and two cases sharing one would be run together while the
// operator believed they had narrowed to a single probe.
$identifiers = [];

foreach ([
    ...array_map(static fn(ControlCase $case): string => $case->id, Cases::verdicts()),
    ...array_map(static fn(JudgementCase $case): string => $case->id, Cases::judgements()),
    ...array_map(static fn(ProbeCase $case): string => $case->id, Cases::probes()),
    ...array_map(static fn(CrossCase $case): string => $case->id, Cases::crossChecks()),
    ...array_map(static fn(GuardCase $case): string => $case->id, Cases::guards()),
] as $identifier) {
    if (isset($identifiers[$identifier])) {
        $stale[] = 'two control cases answer to the id ' . $identifier;
    }

    $identifiers[$identifier] = true;
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

// WHAT USED TO STAND HERE, and why it cannot stand any more.
//
// The run refused (exit 3) unless the frozen half reproduced the published
// grid cell for cell. That held for one reason only: both were measured on
// `6a833ab8`, the same tree. The round has since cured the product, so the
// published grid is the CURED tree and the frozen half is the pre-cure one —
// they now differ by thousands of cells, and they are supposed to. Demanding
// equality would refuse for ever, on the very property the round was built to
// produce; the first version of this package only postponed that by
// suspending the demand while the input stamp was stale, which is not a fix
// because the stamp goes fresh again on the next measurement.
//
// What the demand protected was "the cases plant into the document a reader
// sees". Two things carry that now, and the loss between them is named rather
// than papered over:
//
//   - every cell a case addresses must exist in the universe of the frozen
//     half — the stale-declaration refusal above, exit 2;
//   - the floor, judged on the frozen half (case F1), which is the claim
//     `01-promise.md` actually makes about that document.
//
// What is lost: nothing here notices if the frozen half stops matching a
// PUBLISHED rendering of itself, because no such rendering is published —
// `composer promise-effect:before` recomputes it on demand.
$publishedComparison = 'retired: the published grid measures the cured tree, the frozen half the pre-cure one';

printf(
    "Coverage arithmetic: %d verdict case(s) over 9 verdicts, %d judgement case(s) over the 8 verdicts of axes C\n"
        . "and E, %d stand case(s), %d cross-check case(s) over both sides of the four sets, and %d guard case(s)\n"
        . "over 4 populations, in a universe of %d cells.\n"
        . "The frozen half against the published grid: %s.\n",
    \count(Cases::verdicts()),
    \count(Cases::judgements()),
    \count(Cases::probes()),
    \count(Cases::crossChecks()),
    \count(Cases::guards()),
    \count($baseline),
    $publishedComparison,
);

// The cheap half is not evidence unless it is READ. Every line collected
// above was thrown away here until this round: `$stale` was filled by three
// guards, never consulted, and the documented exit 2 could not happen — so a
// case addressing a cell the run does not carry, a verdict nothing plants, and
// a population nothing guards were all silently tolerated. Named as a defect
// of the stand rather than quietly repaired, because a guard that cannot
// redden is the class this whole programme measures.
if ($stale !== []) {
    foreach ($stale as $problem) {
        fwrite(\STDERR, 'COVERAGE: ' . $problem . "\n");
    }

    $workspace->cleanup();

    exit(2);
}

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

foreach (Cases::probes() as $probe) {
    if ($only !== [] && !\in_array($probe->id, $only, true)) {
        continue;
    }

    ++$ran;
    $failures = [...$failures, ...runProbeCase($probe, $root, $inProcess, $process)];
    printf("  %-3s %-15s %s\n", $probe->id, $probe->subject, $probe->intent);
}

foreach (Cases::judgements() as $judgementCase) {
    if ($only !== [] && !\in_array($judgementCase->id, $only, true)) {
        continue;
    }

    ++$ran;
    $failures = [...$failures, ...runJudgementCase($judgementCase)];
    printf("  %-3s %-15s %s\n", $judgementCase->id, $judgementCase->verdict, $judgementCase->intent);
}

foreach (Cases::crossChecks() as $cross) {
    if ($only !== [] && !\in_array($cross->id, $only, true)) {
        continue;
    }

    ++$ran;
    $tree = $workspace->checkout();
    $before = crossCheckLines($tree, $rules);

    // An absolute assertion, beside the differential ones: a comparison that
    // ignored the door normalization would produce the SAME difference under a
    // planting while being wrong everywhere, and no before/after case can see
    // that. These keys are the round's own worked example — a quoted number is
    // refused in YAML and is the only way to type a number on a CLI door.
    foreach ($cross->absent as $key) {
        if (isset($before[$key])) {
            $failures[] = $cross->id . ': ' . $key . ' is in the unplanted comparison, and a door-aware comparison does not put it there';
        }
    }

    foreach ($cross->ledger as [$prefix, $column, $cell]) {
        (new Planter($tree))->apply(new ControlCase('plant', '', '', [], [[$prefix, $column, $cell]], []));
    }

    $after = crossCheckLines($tree, $rules, $cross->shape);
    $added = array_keys(array_diff_key($after, $before));
    $removed = array_keys(array_diff_key($before, $after));
    sort($added, \SORT_STRING);
    sort($removed, \SORT_STRING);
    $expectedAdded = $cross->added;
    $expectedRemoved = $cross->removed;
    sort($expectedAdded, \SORT_STRING);
    sort($expectedRemoved, \SORT_STRING);

    if ($added !== $expectedAdded) {
        $failures[] = $cross->id . ': expected the planting to add ' . implode(', ', $expectedAdded) . '; it added ' . ($added === [] ? 'nothing' : implode(', ', $added));
    }

    if ($removed !== $expectedRemoved) {
        $failures[] = $cross->id . ': expected the planting to remove ' . implode(', ', $expectedRemoved) . '; it removed ' . ($removed === [] ? 'nothing' : implode(', ', $removed));
    }

    printf("  %-3s %-15s %s\n", $cross->id, $cross->side, $cross->intent);
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
