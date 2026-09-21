<?php

declare(strict_types=1);

/**
 * The grid: every ledger row, every probe, every verdict.
 *
 * Three axes, and the exit code is owned by two of them. Axis B is measured
 * and not cured in this round — no owner holds a mandate over the semantics of
 * a key pair — so `MISCOMPOSED` travels with a number and does not redden the
 * run. That is a decision of the round, recorded here rather than implied.
 */

namespace Qualimetrix\PromiseEffect;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

final readonly class Cell
{
    public function __construct(
        public string $axis,
        public string $key,
        public string $probe,
        public string $point,
        public string $verdict,
        public string $decidedBy,
        public string $status,
        public bool $defect = false,
    ) {}
}

final class Stand
{
    public const string SNAPSHOT_DIR = 'docs/internal/generated/promise-effect';

    private const string UNWRITABLE = 'a valueless flag carries no spelling for this form';

    /** @var array<string, bool> producer name => was it seen to run */
    private array $witnesses = [];

    /**
     * Raw observations, for the frozen snapshot: `axis`, row key, side,
     * outcome, text. Every side the classifier reads is stored, because the
     * frozen half is re-judged by TODAY's classifier and a snapshot missing a
     * side would force the two halves apart in silence.
     *
     * @var list<array{string, string, string, string, string}>
     */
    private array $raw = [];

    /** @var list<string> */
    private array $failures = [];

    private ?CompositionPlanner $planner = null;

    private ?KeyPairGroups $keyPairGroups = null;

    private ?Neighbourhood $neighbourhood = null;

    /**
     * Cells a declared observability limit covered, the verdict they would
     * carry without it, and the kind of the row that covered them — the kind
     * because the guard judges the two kinds by different rules.
     *
     * @var list<array{string, string, string}>
     */
    private array $unrestricted = [];

    public function __construct(
        private readonly string $root,
        private readonly Ledger $ledger,
        private readonly Declarations $declarations,
        private readonly InProcess $inProcess,
        private readonly ProcessProbe $process,
        private readonly ?Limits $limits = null,
    ) {}

    /**
     * What the cells under a declared limit would read without it — the input
     * to {@see Limits::conflicts()}, which refuses a limit declared over an
     * observation the stand actually makes.
     *
     * @return list<array{string, string, string}>
     */
    public function unrestricted(): array
    {
        return $this->unrestricted;
    }

    private function limits(): Limits
    {
        return $this->limits ?? Limits::load($this->root);
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<array{string, string, string, string, string}> */
    public function rawObservations(): array
    {
        return $this->raw;
    }

    /**
     * One process run per producer name, with only that producer enabled. A
     * class-keyed witness would be wrong three times over: `TypeCoverageOptions`
     * serves three rules, `ComputedMetricRuleOptions` eight, and six `health.*`
     * producers have no options class at all — while the hole the round
     * measured is exactly a rule-shaped one.
     */
    /**
     * The in-process points see an exception, not a frame, and the round's
     * REFUSES verdict is defined by the frame. So the mapping is not assumed:
     * two process runs prove it every time the stand runs — one value the
     * product refuses with its own framing, one it refuses without. A stand
     * that guessed here would report a product defect as correct behaviour, or
     * the reverse.
     *
     * @return list<string> what went wrong, empty when the mapping holds
     */
    public function proveRefusalFraming(): array
    {
        $framed = $this->process->observe(['rules' => ['complexity.ccn' => ['callable' => ['warning' => 'abc']]]]);

        // A negative worker count: refused by
        // `ParallelConfigurationResolver` with a bare
        // `InvalidArgumentException`, which the console catches as its
        // FALLBACK refusal — exit 3 with no `Configuration error:` frame.
        //
        // The invariant `--workers=0` is withdrawn, or the probe would hand
        // the product two values for one flag and measure the parser instead
        // of the resolver.
        //
        // This control stood on `--layer-violation-severity=true` until the
        // first cure package framed it, and the replacement is chosen to
        // outlive the same fate rather than to be merely different: the
        // subject is a ROOT flag whose value is judged by an infrastructure
        // resolver, so no rule-option key, spelling or registry — the whole
        // material of axis C — can reach it. Its predecessor was a rule-option
        // alias, which is why it died. The control is still mortal, as it must
        // be: when this refusal is framed too, the stand exits 3 instead of
        // reporting, and `php scripts/enumerate-refusal-fallback.php` is where
        // the next subject is found.
        $unframed = $this->process->observe([], ['--workers=-5'], false, 'findings', ['--workers']);

        $this->raw[] = ['control', 'refusal-framing', 'framed', $framed->outcome(), $framed->text()];
        $this->raw[] = ['control', 'refusal-framing', 'unframed', $unframed->outcome(), $unframed->text()];

        return self::framingProblems(
            $framed->outcome(),
            $framed->text(),
            $unframed->outcome(),
            $unframed->text(),
        );
    }

    /**
     * The judgement the two framing probes are put to, as a function of what
     * they observed — separated from the probes so a control can plant an
     * outcome into it. A control that could only run the real probes could
     * never show this judgement going red without breaking the product.
     *
     * @return list<string>
     */
    public static function framingProblems(string $framedOutcome, string $framedText, string $unframedOutcome, string $unframedText): array
    {
        $problems = [];

        if ($framedOutcome !== Observation::REFUSED_FRAMED) {
            $problems[] = 'a ConfigurationRefusal no longer reaches the user framed: ' . $framedText;
        }

        if ($unframedOutcome !== Observation::REFUSED_UNFRAMED) {
            $problems[] = 'the unframed refusal control changed shape: ' . $unframedText;
        }

        return $problems;
    }

    public function takeWitnesses(): void
    {
        foreach ($this->inProcess->producerNames() as $producer) {
            // Seven producers report on a configuration section and have
            // nothing to say to an empty document. Their declared envelope is
            // in `witness-envelopes.tsv`: it is the smallest document that
            // makes the producer's subject exist, and it reaches no probe but
            // this one.
            $envelope = $this->declarations->witnessEnvelopes[$producer] ?? null;
            $observable = $envelope === null || $envelope->channel === ''
                ? 'findings'
                : 'channel:' . $envelope->channel;

            try {
                $observation = $this->process->observe(
                    $envelope === null ? [] : $envelope->document,
                    ['--only-rule=' . $producer, ...($envelope === null ? [] : $envelope->arguments)],
                    false,
                    $observable,
                );
            } catch (ProbeFailure $failure) {
                $this->failures[] = 'witness ' . $producer . ': ' . $failure->getMessage();
                $this->witnesses[$producer] = false;

                continue;
            }

            // Reachable means the producer ran and had something to say on the
            // fixture. A producer that says nothing here is not proof of a
            // dead producer — it is proof this stand cannot witness it, which
            // is why the verdict it blocks is NOT OBSERVABLE and not a defect.
            $this->witnesses[$producer] = $observation->outcome() === Observation::ACCEPTED
                && !str_ends_with($observation->digest, '/n=0');

            $this->raw[] = [
                'witness',
                $producer,
                $this->witnesses[$producer] ? 'reachable' : 'unwitnessed',
                $observation->outcome(),
                $observation->digest . ' cache=' . $observation->cacheNote
                    . ' envelope=' . ($envelope === null ? 'none' : json_encode($envelope->document, \JSON_UNESCAPED_SLASHES)),
            ];
        }
    }

    /**
     * The frozen half, re-judged. It reads the stored RAW sides and runs
     * TODAY's classifier over them, so both halves of the before/after pair
     * are judged by one rule — which is the whole reason verdicts are not
     * what gets frozen.
     *
     * @param list<array{string, string, string, string, string}> $raw
     *
     * @return list<Cell>
     */
    public function before(array $raw): array
    {
        $sides = [];
        $witnesses = [];

        foreach ($raw as [$axis, $key, $side, $outcome, $text]) {
            if ($axis === 'witness') {
                $witnesses[$key] = $side === 'reachable';

                continue;
            }

            // Read through the one normalization both halves share, never
            // through the constructor: an exit code is turned into an outcome
            // in exactly one place, or the frozen half and a fresh
            // measurement are judged by two rules.
            $sides[$axis . "\0" . $key . "\0" . $side] = Observation::ofMeasured($outcome, $text);
        }

        $cells = [];

        foreach ($this->ledger->forms as $row) {
            $axis = str_starts_with($row->path, 'rules.') ? 'A' : 'D';
            $split = $axis === 'A' ? $this->split($row->path) : null;
            $rule = $split === null ? '' : $split[0];
            $base = $axis === 'A' ? $row->key() : $row->key();
            $omitted = $sides[$axis . "\0" . $base . "\0" . 'omitted'] ?? null;
            $equivalent = $sides[$axis . "\0" . $base . "\0" . 'equivalent'] ?? null;

            if ($omitted === null || $equivalent === null) {
                continue;
            }

            foreach ($this->declarations->formNames() as $form) {
                $cellKey = $row->key() . '|' . $form;

                if (isset($sides[$axis . "\0" . $cellKey . "\0" . 'unwritable'])) {
                    $cells[] = new Cell($axis, $cellKey, $form, 'report', Verdict::NOT_OBSERVABLE, self::UNWRITABLE, $row->status, false);

                    continue;
                }

                $value = $sides[$axis . "\0" . $cellKey . "\0" . 'value'] ?? null;

                if ($value === null) {
                    continue;
                }

                $judgement = $this->judge(
                    $omitted,
                    $value,
                    $equivalent,
                    $sides[$axis . "\0" . $cellKey . "\0" . 'collapse'] ?? null,
                    $form,
                    $row,
                    $axis === 'A' ? ($witnesses[$rule] ?? false) : true,
                    $cellKey,
                );

                $cells[] = new Cell($axis, $cellKey, $form, $axis === 'A' ? 'optionsObject' : 'report', $judgement->verdict, $judgement->decidedBy, $row->status, $judgement->defect);
            }
        }

        foreach ($this->ledger->pairs as $row) {
            $omitted = $sides['B' . "\0" . $row->key() . "\0" . 'omitted'] ?? null;
            $a = $sides['B' . "\0" . $row->key() . "\0" . 'onlyA'] ?? null;
            $b = $sides['B' . "\0" . $row->key() . "\0" . 'onlyB'] ?? null;
            $both = $sides['B' . "\0" . $row->key() . "\0" . 'both'] ?? null;

            if ($omitted === null || $a === null || $b === null || $both === null) {
                continue;
            }

            $coexistence = $row->coexistence;

            if (str_starts_with($coexistence, 'one-wins:')) {
                $coexistence = 'one-wins:' . (substr($coexistence, \strlen('one-wins:')) === $row->keyA ? 'a' : 'b');
            }

            $judgement = Classifier::pair($omitted, $a, $b, $both, $coexistence);
            $cells[] = new Cell('B', $row->key(), 'both', 'optionsObject', $judgement->verdict, $judgement->decidedBy, $row->status, $judgement->defect);
        }

        foreach ($this->ledger->compositions as $row) {
            [$rule, $option] = $this->compositionSubject($row);

            foreach (self::compositionPoints($row) as $point) {
                $cellKey = $row->key() . '|' . $point;
                $unplanned = $sides['C' . "\0" . $cellKey . "\0" . 'unplanned'] ?? null;

                if ($unplanned !== null) {
                    $cells[] = new Cell('C', $cellKey, 'both', $point, Verdict::NOT_OBSERVABLE, $unplanned->text, $row->status);

                    continue;
                }

                $omitted = $sides['C' . "\0" . $cellKey . "\0" . 'omitted'] ?? null;
                $low = $sides['C' . "\0" . $cellKey . "\0" . 'onlyLow'] ?? null;
                $high = $sides['C' . "\0" . $cellKey . "\0" . 'onlyHigh'] ?? null;
                $both = $sides['C' . "\0" . $cellKey . "\0" . 'both'] ?? null;

                if ($omitted === null || $low === null || $high === null || $both === null) {
                    continue;
                }

                // Re-planned, not replayed: the note is part of what the cell
                // says, and rebuilding it from the plan keeps the two halves
                // reading one text rather than one stored and one derived.
                $plan = $rule === '' || !isset($this->inProcess->optionsClasses[$rule])
                    ? null
                    : $this->planner()->plan($row, $rule, $option, $point);
                $note = $plan instanceof CompositionPlan ? $plan->note : '';
                $objectLow = $sides['C' . "\0" . $cellKey . "\0" . 'objectLow'] ?? null;
                $objectHigh = $sides['C' . "\0" . $cellKey . "\0" . 'objectHigh'] ?? null;
                $blindness = $objectLow === null || $objectHigh === null ? null : self::objectBlindness($objectLow, $objectHigh);
                // Absent, not fabricated, on a snapshot taken before this
                // side existed: a triple's `middle` was never stored under
                // that key, and `Classifier::composition()` is the one place
                // that decides what a missing middle observation means for
                // `promised_survival=survives` — never assumed here as a pass.
                $middle = $sides['C' . "\0" . $cellKey . "\0" . 'middle'] ?? null;
                $judgement = Classifier::composition(
                    $omitted,
                    $low,
                    $high,
                    $both,
                    $row->promised,
                    $plan instanceof CompositionPlan && $plan->highRewritesEveryLowKey(),
                    middle: $middle,
                );
                $cells[] = new Cell(
                    'C',
                    $cellKey,
                    'both',
                    $point,
                    $judgement->verdict,
                    $judgement->decidedBy
                        . '; promised ' . ($row->promised === '' ? 'nothing' : $row->promised)
                        . ($note === '' ? '' : '; ' . $note)
                        . ($blindness === null ? '' : '; ' . $blindness),
                    $row->status,
                    $judgement->defect,
                );
            }
        }

        foreach ($this->neighbourhood()->rows as $row) {
            $unplanned = $sides['E' . "\0" . $row->key() . "\0" . 'unplanned'] ?? null;

            if ($unplanned !== null) {
                $cells[] = new Cell('E', $row->key(), 'both', 'optionsObject', Verdict::NOT_OBSERVABLE, $unplanned->text, '(none)');

                continue;
            }

            $omitted = $sides['E' . "\0" . $row->key() . "\0" . 'omitted'] ?? null;
            $neighbour = $sides['E' . "\0" . $row->key() . "\0" . 'neighbour'] ?? null;
            $nullAlone = $sides['E' . "\0" . $row->key() . "\0" . 'nullAlone'] ?? null;
            $both = $sides['E' . "\0" . $row->key() . "\0" . 'both'] ?? null;

            if ($omitted === null || $neighbour === null || $nullAlone === null || $both === null) {
                continue;
            }

            $judgement = Classifier::neighbourhood($omitted, $neighbour, $nullAlone, $both);
            $cells[] = new Cell(
                'E',
                $row->key(),
                'both',
                'optionsObject',
                $judgement->verdict,
                $judgement->decidedBy . '; pair kind ' . $row->pairKind,
                '(none)',
                $judgement->defect,
            );
        }

        return [...$cells, ...$this->unpromised($sides)];
    }

    /**
     * The seventh verdict, and the only one no probe can produce: it asks
     * whether the GRID grew past the ledger. An observation whose row the
     * ledger does not carry is a question nobody promised an answer to, and
     * leaving it out of the grid would make the growth silent — which is the
     * one thing 02 §2 asks this verdict to prevent.
     *
     * @param array<string, Observation> $sides
     *
     * @return list<Cell>
     */
    private function unpromised(array $sides): array
    {
        $known = [];

        foreach ($this->ledger->forms as $row) {
            $known[$row->key()] = true;
        }

        foreach ($this->ledger->pairs as $row) {
            $known[$row->key()] = true;
        }

        foreach ($this->compositionKeys() as $key) {
            $known[$key] = true;
        }

        foreach ($this->neighbourhood()->rows as $row) {
            $known[$row->key()] = true;
        }

        $cells = [];
        $seen = [];

        foreach (array_keys($sides) as $composite) {
            [$axis, $key, $side] = explode("\0", $composite);

            if ($axis === 'control' || !\in_array($side, ['value', 'unwritable', 'both'], true)) {
                continue;
            }

            // A form cell carries the form as its last segment; a pair cell is
            // the row key itself.
            $base = $side === 'both' ? $key : substr($key, 0, (int) strrpos($key, '|'));

            if (isset($known[$base]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $cells[] = new Cell($axis, $key, $side, 'report', Verdict::UNPROMISED, 'observed, and the ledger carries no such row', '(none)', true);
        }

        return $cells;
    }

    /** @return list<Cell> */
    public function axisA(): array
    {
        $cells = [];

        foreach ($this->ledger->forms as $row) {
            if (!str_starts_with($row->path, 'rules.')) {
                continue;
            }

            $split = $this->split($row->path);

            if ($split === null) {
                $cells[] = new Cell('A', $row->key(), '-', 'optionsObject', Verdict::NOT_OBSERVABLE, 'no registered producer owns this path', $row->status);

                continue;
            }

            [$rule, $option] = $split;
            $omitted = $this->write($row, $rule, $option, null);
            $reference = $this->referenceForm($row, $rule, $option, $omitted);
            // The declared hit replaces the MAGNITUDE of the reference form
            // wherever the canonical 7331 names nothing this stand can see —
            // the three framework keys are observed through
            // `isPathExcluded()` / `isNamespaceExcluded()`, and a path that is
            // not in the fixture excludes nothing. The form the write stands
            // for is unchanged.
            $hit = $reference === null ? '' : $this->hitFor($option);
            $referenceSpelling = $reference === null
                ? null
                : ($hit === '' ? $this->declarations->forms[$reference] : new FormSpelling($reference, $hit, $hit, '', ''));
            $equivalent = $referenceSpelling === null
                ? $omitted
                : $this->write($row, $rule, $option, $referenceSpelling, true);

            $this->record('A', $row->key(), 'omitted', $omitted['object']);
            $this->record('A', $row->key(), 'equivalent', $equivalent['object']);

            foreach ($this->declarations->formNames() as $form) {
                // The hit is the reference form's own write too. Substituting
                // it only into `equivalent` would leave this cell writing a
                // magnitude that names nothing, and the stand would read the
                // correct behaviour — "a list of a path that is not here
                // excludes nothing" — as the INERT defect.
                $spelling = $hit !== '' && $form === $reference && $referenceSpelling !== null
                    ? $referenceSpelling
                    : $this->declarations->forms[$form];
                $value = $this->write($row, $rule, $option, $spelling);

                // The comparand costs a probe and only ever decides a cell the
                // product accepted and moved; taking it for a refused form buys
                // nothing and, on axis D, buys it with a process.
                $collapse = $spelling->collapseYaml === '' || !$value['object']->accepted() || $value['object']->text === $omitted['object']->text
                    ? null
                    : $this->write($row, $rule, $option, $spelling->comparand());

                // The verdict is taken at the DEEPEST point, and the three
                // shallower ones say WHERE the form was lost. Judging the door
                // in its own right would call every unpromised form a defect
                // there: the door's job is to carry the value, and the refusal
                // that was promised is owed one layer down. `warning: true` is
                // still `true` at the door and `1` only after the factory —
                // that asymmetry is the reason the stand keeps four points and
                // the reason only one of them votes.
                $judgement = $this->judge(
                    $omitted['object'],
                    $value['object'],
                    $equivalent['object'],
                    $collapse === null ? null : $collapse['object'],
                    $form,
                    $row,
                    $this->witnesses[$rule] ?? false,
                    $row->key() . '|' . $form,
                );

                $cells[] = new Cell(
                    'A',
                    $row->key() . '|' . $form,
                    $form,
                    'optionsObject',
                    $judgement->verdict,
                    $judgement->decidedBy . '; lost at ' . self::lostAt($row->door, $omitted, $value),
                    $row->status,
                    $judgement->defect,
                );

                $this->record('A', $row->key() . '|' . $form, 'value', $value['object']);

                if ($collapse !== null) {
                    $this->record('A', $row->key() . '|' . $form, 'collapse', $collapse['object']);
                }
            }
        }

        return $cells;
    }

    /** @return list<Cell> */
    public function axisB(): array
    {
        $cells = [];

        foreach ($this->ledger->pairs as $row) {
            if ($row->sourceScope !== 'same-source') {
                continue;
            }

            $rule = $row->rule;

            if (!isset($this->inProcess->optionsClasses[$rule])) {
                $cells[] = new Cell('B', $row->key(), 'both', 'optionsObject', Verdict::MISCOMPOSED, 'no registered producer owns this rule', $row->status, true);

                continue;
            }

            $omitted = $this->pairWrite($rule, []);
            [$valueA, $a, $chosenA] = $this->pairSide($rule, $row->keyA, $omitted['object']);
            [$valueB, $b, $chosenB] = $this->pairSide($rule, $row->keyB, $omitted['object'], $this->declarations->sideBLiterals);
            $both = $this->pairWrite($rule, [$row->keyA => $valueA, $row->keyB => $valueB]);

            $coexistence = $row->coexistence;

            if (str_starts_with($coexistence, 'one-wins:')) {
                $winner = substr($coexistence, \strlen('one-wins:'));
                $coexistence = 'one-wins:' . ($winner === $row->keyA ? 'a' : 'b');
            }

            $judgement = Classifier::pair($omitted['object'], $a['object'], $b['object'], $both['object'], $coexistence);
            $cells[] = new Cell(
                'B',
                $row->key(),
                'both',
                'optionsObject',
                $judgement->verdict,
                $judgement->decidedBy . '; A wrote the ' . $chosenA . ', B the ' . $chosenB,
                $row->status,
                $judgement->defect,
            );

            $this->record('B', $row->key(), 'omitted', $omitted['object']);
            $this->record('B', $row->key(), 'onlyA', $a['object']);
            $this->record('B', $row->key(), 'onlyB', $b['object']);
            $this->record('B', $row->key(), 'both', $both['object']);
        }

        return $cells;
    }

    /**
     * Axis C: of the layers that wrote one path, whose value survived.
     *
     * Two points per `composition-path` row, one for the other two kinds. The
     * second point is not decoration and it is not optional: the three
     * framework keys are drained by `RuleOptionsFactory::create()` before
     * `fromArray()` runs, so they reach no field of any options object, and a
     * grid taken only at `optionsObject` would be green about them whatever
     * the merge did. It is taken where the consumers of the merged document
     * read them — the registry's own predicates.
     *
     * @return list<Cell>
     */
    public function axisC(): array
    {
        $cells = [];

        foreach ($this->ledger->compositions as $row) {
            [$rule, $option] = $this->compositionSubject($row);

            foreach (self::compositionPoints($row) as $point) {
                $cellKey = $row->key() . '|' . $point;

                $plan = $rule === '' || !isset($this->inProcess->optionsClasses[$rule])
                    ? new CompositionRefusal('no registered producer owns "' . $row->subject . '"')
                    : $this->planner()->plan($row, $rule, $option, $point);

                if ($plan instanceof CompositionRefusal) {
                    $cells[] = new Cell('C', $cellKey, 'both', $point, Verdict::NOT_OBSERVABLE, $plan->reason, $row->status);
                    // Recorded although nothing was run: a cell the frozen
                    // half cannot rebuild is a cell the two halves silently
                    // stop comparing — the lesson axis D's `unwritable` side
                    // already carries.
                    $this->record('C', $cellKey, 'unplanned', new Observation(Observation::ACCEPTED, $plan->reason));

                    continue;
                }

                $member = $point === CompositionPlanner::POINT_FRAMEWORK ? 'framework' : 'object';
                $omitted = $this->compositionWrite($plan, [], $member);
                $low = $this->compositionWrite($plan, $plan->low, $member);
                $high = $this->compositionWrite($plan, $plan->high, $member);
                $both = $this->compositionWrite($plan, $plan->both, $member);
                // Only a composition-triple plan carries a middle layer to
                // probe alone; a pair's dispute is already fully described by
                // `low` and `high`. See `CompositionPlan::$middle`.
                $middle = $plan->middle === [] ? null : $this->compositionWrite($plan, $plan->middle, $member);

                // The evidence that the second point is not decoration: the
                // SAME two writes, read at `optionsObject`. Recorded as sides
                // of their own so the claim is rebuilt by the classifier on
                // both halves rather than asserted once in a report.
                $blindness = null;

                if ($point === CompositionPlanner::POINT_FRAMEWORK) {
                    $objectLow = $this->compositionWrite($plan, $plan->low, 'object');
                    $objectHigh = $this->compositionWrite($plan, $plan->high, 'object');
                    $this->record('C', $cellKey, 'objectLow', $objectLow);
                    $this->record('C', $cellKey, 'objectHigh', $objectHigh);
                    $blindness = self::objectBlindness($objectLow, $objectHigh);
                }

                $judgement = Classifier::composition($omitted, $low, $high, $both, $row->promised, $plan->highRewritesEveryLowKey(), middle: $middle);
                $cells[] = new Cell(
                    'C',
                    $cellKey,
                    'both',
                    $point,
                    $judgement->verdict,
                    $judgement->decidedBy
                        . '; promised ' . ($row->promised === '' ? 'nothing' : $row->promised)
                        . ($plan->note === '' ? '' : '; ' . $plan->note)
                        . ($blindness === null ? '' : '; ' . $blindness),
                    $row->status,
                    $judgement->defect,
                );

                $this->record('C', $cellKey, 'omitted', $omitted);
                $this->record('C', $cellKey, 'onlyLow', $low);
                $this->record('C', $cellKey, 'onlyHigh', $high);
                $this->record('C', $cellKey, 'both', $both);

                if ($middle !== null) {
                    $this->record('C', $cellKey, 'middle', $middle);
                }
            }
        }

        return $cells;
    }

    /**
     * Axis E: `~` written BESIDE a neighbour.
     *
     * One document, four writings of it, and the comparison that matters is
     * `both` against the NEIGHBOUR ALONE — the claim is that the presence of a
     * key written `~` changes nothing about how its neighbour is read.
     *
     * @return list<Cell>
     */
    public function axisE(): array
    {
        $cells = [];

        foreach ($this->neighbourhood()->rows as $row) {
            $candidates = isset($this->inProcess->optionsClasses[$row->rule])
                ? $this->neighbourWrite($row)
                : 'no registered producer owns this rule';

            if (\is_string($candidates)) {
                $neighbourWrite = $candidates;
                $cells[] = new Cell('E', $row->key(), 'both', 'optionsObject', Verdict::NOT_OBSERVABLE, $neighbourWrite, '(none)');
                $this->record('E', $row->key(), 'unplanned', new Observation(Observation::ACCEPTED, $neighbourWrite));

                continue;
            }

            $nullWrite = [$row->nullKey => null];

            // Fail closed rather than merge: if the neighbour's own write
            // carried this key too, the array spread below would silently drop
            // the `~` and the row would measure the neighbour against itself.
            // No pair in today's table does — a block neighbour writes keys
            // UNDER itself and the `~` key is always at another depth — but a
            // future row that did would be a green cell measuring nothing.
            if (\array_key_exists($row->nullKey, $candidates[0])) {
                $cells[] = new Cell('E', $row->key(), 'both', 'optionsObject', Verdict::NOT_OBSERVABLE, 'the neighbour writes the same key, so `~` could not stand beside it', '(none)');
                $this->record('E', $row->key(), 'unplanned', new Observation(Observation::ACCEPTED, 'the neighbour writes the same key, so `~` could not stand beside it'));

                continue;
            }

            $omitted = $this->neighbourhoodWrite($row->rule, []);
            [$neighbourWrite, $neighbour, $chosen] = $this->neighbourSide($row->rule, $candidates, $omitted);
            $nullAlone = $this->neighbourhoodWrite($row->rule, $nullWrite);
            $both = $this->neighbourhoodWrite($row->rule, [...$nullWrite, ...$neighbourWrite]);

            $judgement = Classifier::neighbourhood($omitted, $neighbour, $nullAlone, $both);
            $cells[] = new Cell(
                'E',
                $row->key(),
                'both',
                'optionsObject',
                $judgement->verdict,
                $judgement->decidedBy . '; pair kind ' . $row->pairKind . '; the neighbour wrote the ' . $chosen,
                '(none)',
                $judgement->defect,
            );

            $this->record('E', $row->key(), 'omitted', $omitted);
            $this->record('E', $row->key(), 'neighbour', $neighbour);
            $this->record('E', $row->key(), 'nullAlone', $nullAlone);
            $this->record('E', $row->key(), 'both', $both);
        }

        return $cells;
    }

    /**
     * The rule and the disputed option of one composition row.
     *
     * A `composition-path` row names a whole path and the producer is found
     * the way axis A finds it; the other two kinds name the rule outright and
     * carry their keys in the packed columns the planner reads.
     *
     * @return array{0: string, 1: string}
     */
    private function compositionSubject(CompositionRow $row): array
    {
        if ($row->kind !== 'composition-path') {
            return [$row->subject, ''];
        }

        $split = str_starts_with($row->subject, 'rules.') ? $this->split($row->subject) : null;

        return $split ?? ['', ''];
    }

    /**
     * The points one row is observed at.
     *
     * `composition-path` gets both, and the framework one is REFUSED with its
     * reason where the row's promise does not reach it rather than dropped:
     * a point that silently disappears for some rows is a grid that shrinks
     * without saying so.
     *
     * @return list<string>
     */
    private static function compositionPoints(CompositionRow $row): array
    {
        return $row->kind === 'composition-path'
            ? [CompositionPlanner::POINT_OBJECT, CompositionPlanner::POINT_FRAMEWORK]
            : [CompositionPlanner::POINT_OBJECT];
    }

    /**
     * One side of one composition probe, written through the real doors.
     *
     * @param list<CompositionWrite> $writes
     */
    private function compositionWrite(CompositionPlan $plan, array $writes, string $member): Observation
    {
        $document = [];
        $presets = [];
        $ruleOpts = [];
        $aliasFlags = [];

        foreach ($writes as $write) {
            $index = self::presetIndex($write->writer);

            if ($index !== null) {
                $presets[$index] = self::place(
                    $presets[$index] ?? [],
                    ['rules', $plan->rule, ...explode('.', $write->key)],
                    self::parse($write->yamlWrite),
                );

                continue;
            }

            if ($write->writer === 'qmx.yaml') {
                $document = self::place($document, ['rules', $plan->rule, ...explode('.', $write->key)], self::parse($write->yamlWrite));

                continue;
            }

            if ($write->writer === 'cli-alias') {
                $flag = $this->planner()->aliasFor($plan->rule, $write->key);

                if ($flag !== null) {
                    $aliasFlags[$flag] = $write->cliWrite;
                }

                continue;
            }

            // `rule-opt` and `cli-bucket`. The denominator's `cli-bucket` is
            // the CLI layer as a whole, and `--rule-opt` is its general door —
            // the one that can write any path, where an alias flag can write
            // eighty. Named here rather than left to the reader.
            $ruleOpts[] = $plan->rule . ':' . $write->key . '=' . $write->cliWrite;
        }

        ksort($presets);

        $observation = $this->inProcess->compose(
            $document,
            array_values($presets),
            $ruleOpts,
            $aliasFlags,
            $plan->rule,
            $plan->pathWitnesses,
        );

        return Observation::ofMeasured($observation[$member]->outcome, $observation[$member]->text);
    }

    /**
     * Which preset file a writer name stands for, lowest layer first.
     *
     * `preset#i`/`preset#j` and `preset#1`..`preset#3` are the denominator's
     * two spellings of the same thing: an ordered list of `--preset` names.
     * `preset` alone is a single one.
     */
    private static function presetIndex(string $writer): ?int
    {
        if ($writer === 'preset') {
            return 0;
        }

        if (!str_starts_with($writer, 'preset#')) {
            return null;
        }

        $suffix = substr($writer, \strlen('preset#'));

        return match ($suffix) {
            'i' => 0,
            'j' => 1,
            default => ctype_digit($suffix) ? (int) $suffix - 1 : null,
        };
    }

    /**
     * What a neighbour is written as, or why it cannot be written.
     *
     * A leaf carries the values of its own declared shape — the same rule S8
     * put under the pair probe. A LEVEL BLOCK carries the band pair its own
     * level declares: `class: {}` is an empty mapping and means "omitted", so
     * a block written empty would measure nothing and read as if the
     * neighbour had no effect.
     *
     * @return non-empty-list<array<string, mixed>>|string the candidate writes
     *                                                     in the order {@see self::neighbourSide()} tries them
     */
    private function neighbourWrite(NeighbourhoodRow $row): array|string
    {
        if (!$row->neighbourIsBlock()) {
            try {
                return array_map(
                    static fn(mixed $value): array => [$row->neighbour => $value],
                    $this->effectWritesFor($row->rule, $row->neighbour),
                );
            } catch (LedgerError $error) {
                return 'the neighbour has no declared shape to write: ' . $error->getMessage();
            }
        }

        $block = $row->neighbourBlock();
        $band = $this->keyPairGroups()->bandPairAt($row->rule, $block);

        if ($band === null) {
            return 'key-pairs.tsv declares no band pair under the block "' . $block . '", so it cannot be written with an effect';
        }

        $perKey = [];

        foreach ($band as $key) {
            try {
                $perKey[$key] = $this->effectWritesFor($row->rule, $key);
            } catch (LedgerError $error) {
                return 'a key of the block "' . $block . '" has no declared shape: ' . $error->getMessage();
            }
        }

        return self::zip($perKey);
    }

    /**
     * The neighbour written with a value the object can be seen to carry.
     *
     * The same search {@see self::pairSide()} runs, for the same reason: this
     * axis asks whether `~` beside a neighbour costs the NEIGHBOUR its effect,
     * and a neighbour written with what the product does anyway has no effect
     * to lose. The classifier already reads that case as NOT OBSERVABLE, so a
     * blind write does not produce a wrong verdict here — it produces a row
     * that measures nothing while looking like a measurement.
     *
     * @param non-empty-list<array<string, mixed>> $candidates
     *
     * @return array{0: array<string, mixed>, 1: Observation, 2: string}
     */
    private function neighbourSide(string $rule, array $candidates, Observation $omitted): array
    {
        $canonical = $this->neighbourhoodWrite($rule, $candidates[0]);

        if ($canonical->accepted() && $canonical->text !== $omitted->text) {
            return [$candidates[0], $canonical, 'canonical magnitude'];
        }

        foreach (\array_slice($candidates, 1) as $candidate) {
            $taken = $this->neighbourhoodWrite($rule, $candidate);

            if ($taken->accepted() && $taken->text !== $omitted->text) {
                return [$candidate, $taken, 'alternate magnitude'];
            }
        }

        return [$candidates[0], $canonical, 'canonical magnitude, which no declared value here can improve on'];
    }

    /**
     * One axis-E writing: dotted keys placed into one document on the yaml
     * door, which is what "beside" means — the neighbourhood is a property of
     * ONE source, and spreading the two keys over two layers would be axis C's
     * question instead.
     *
     * @param array<string, mixed> $keys
     */
    private function neighbourhoodWrite(string $rule, array $keys): Observation
    {
        $options = [];

        /** @var mixed $value */
        foreach ($keys as $key => $value) {
            $options = self::place($options, explode('.', $key), $value);
        }

        $taken = $this->inProcess->take($options === [] ? [] : ['rules' => [$rule => $options]], [], [], $rule);

        return $taken['object'];
    }

    /**
     * Whether the options object can tell the two sides of a framework-key
     * dispute apart — the claim under the second observation point, stated in
     * the cell that rests on it.
     */
    private static function objectBlindness(Observation $objectLow, Observation $objectHigh): string
    {
        return $objectLow->text === $objectHigh->text
            ? 'at optionsObject the same two writes are indistinguishable, which is the whole reason for this point'
            : 'optionsObject tells these two writes apart as well';
    }

    /**
     * Every cell key axis C owes, point included.
     *
     * One place, read by the span assertion, by the growth verdict and by the
     * cheap freshness check — the three that would otherwise each carry their
     * own copy of the arithmetic and drift apart.
     *
     * @return list<string>
     */
    public function compositionKeys(): array
    {
        $keys = [];

        foreach ($this->ledger->compositions as $row) {
            foreach (self::compositionPoints($row) as $point) {
                $keys[] = $row->key() . '|' . $point;
            }
        }

        return $keys;
    }

    private function planner(): CompositionPlanner
    {
        return $this->planner ??= new CompositionPlanner($this->declarations, $this->inProcess->aliases, $this->keyPairGroups());
    }

    private function keyPairGroups(): KeyPairGroups
    {
        return $this->keyPairGroups ??= new KeyPairGroups($this->root);
    }

    private function neighbourhood(): Neighbourhood
    {
        return $this->neighbourhood ??= new Neighbourhood($this->root, $this->inProcess->optionsClasses);
    }

    /** @return list<Cell> */
    public function axisD(): array
    {
        $cells = [];
        $flags = self::table($this->root . '/promise-effect/cli-root-flags.tsv', 5);
        $observables = self::table($this->root . '/promise-effect/axis-d-observables.tsv', 4);

        foreach ($this->ledger->forms as $row) {
            if (str_starts_with($row->path, 'rules.')) {
                continue;
            }

            $envelope = $this->declarations->envelopes[$row->path] ?? null;
            $cacheOwned = $envelope !== null && $envelope->cacheOwned;
            $writePath = $envelope === null ? $row->path : $envelope->writePath;
            $base = $envelope === null ? [] : $envelope->base;

            $flag = $row->door === 'cli-root' ? ($flags[$row->path] ?? null) : null;

            if ($row->door === 'cli-root' && $flag === null) {
                $cells[] = new Cell('D', $row->key(), '-', 'report', Verdict::NOT_OBSERVABLE, 'no declared flag for this root', $row->status);

                continue;
            }

            $unit = $observables[$row->path] ?? null;
            $observable = $unit === null || $unit[1] === '' ? 'findings' : $unit[1];
            $hit = $unit === null ? '' : $unit[3];
            $withdraw = $unit === null || $unit[2] === '' ? [] : [$unit[2]];

            if ($flag !== null && $flag[3] !== '') {
                $withdraw = [...$withdraw, $flag[3]];
            }

            $omitted = $this->rootProbe($row, $writePath, $base, null, $flag, $cacheOwned, $observable, $withdraw);
            $reference = null;

            foreach (array_diff($row->promisedForms, ['null']) as $candidate) {
                $reference = $candidate;

                break;
            }

            // The declared hit replaces the canonical write wherever the
            // canonical one names nothing in the fixture; the form it stands
            // for is the same.
            $equivalent = $reference === null
                ? $omitted
                : $this->rootProbe(
                    $row,
                    $writePath,
                    $base,
                    $hit === '' ? $this->declarations->forms[$reference] : new FormSpelling($reference, $hit, $hit, '', ''),
                    $flag,
                    $cacheOwned,
                    $observable,
                    $withdraw,
                );

            $referenceSpelling = $reference === null
                ? null
                : ($hit === '' ? $this->declarations->forms[$reference] : new FormSpelling($reference, $hit, $hit, '', ''));

            foreach ($this->declarations->formNames() as $form) {
                // Same rule as axis A, and the reason it is the same rule: a
                // hit substituted only into `equivalent` made
                // `suppress_paths: [7331]` read INERT, which is the stand
                // calling correct behaviour a product defect.
                $spelling = $hit !== '' && $form === $reference && $referenceSpelling !== null
                    ? $referenceSpelling
                    : $this->declarations->forms[$form];

                if ($flag !== null && $flag[2] === 'yes' && $form !== 'bool') {
                    $cells[] = new Cell('D', $row->key() . '|' . $form, $form, 'report', Verdict::NOT_OBSERVABLE, self::UNWRITABLE, $row->status, false);
                    // Recorded even though nothing was run: a cell the frozen
                    // half cannot reconstruct is a cell the two halves are
                    // silently not comparing.
                    $this->record('D', $row->key() . '|' . $form, 'unwritable', new Observation(Observation::ACCEPTED, ''));

                    continue;
                }

                $value = $this->rootProbe($row, $writePath, $base, $spelling, $flag, $cacheOwned, $observable, $withdraw);
                $collapse = $spelling->collapseYaml === '' || !$value->accepted() || $value->text === $omitted->text
                    ? null
                    : $this->rootProbe($row, $writePath, $base, $spelling->comparand(), $flag, $cacheOwned, $observable, $withdraw);

                $judgement = $this->judge(
                    $omitted,
                    $value,
                    $equivalent,
                    $collapse,
                    $form,
                    $row,
                    true,
                    $row->key() . '|' . $form,
                );

                $cells[] = new Cell('D', $row->key() . '|' . $form, $form, 'report', $judgement->verdict, $judgement->decidedBy, $row->status, $judgement->defect);
                $this->record('D', $row->key() . '|' . $form, 'value', $value);

                if ($collapse !== null) {
                    $this->record('D', $row->key() . '|' . $form, 'collapse', $collapse);
                }
            }

            $this->record('D', $row->key(), 'omitted', $omitted);
            $this->record('D', $row->key(), 'equivalent', $equivalent);
        }

        return $cells;
    }

    /**
     * A root probe is a process: the effect of a key outside `rules:` is not
     * visible in any options object, and the two forms of defect the round
     * measured out there — an accepted key with no effect, and an internal
     * error instead of a refusal — both only show at the report.
     *
     * @param array<string, mixed> $base
     * @param list<string>|null $flag
     * @param list<string> $withdraw
     */
    private function rootProbe(FormRow $row, string $writePath, array $base, ?FormSpelling $spelling, ?array $flag, bool $cacheOwned, string $observable, array $withdraw): Observation
    {
        $document = $base;
        $arguments = [];

        if ($spelling !== null) {
            if ($row->door === 'cli-root' && $flag !== null) {
                $arguments[] = match (true) {
                    $flag[1] === '(positional)' => $spelling->cliWrite,
                    $flag[2] === 'yes' => $flag[1],
                    default => $flag[1] . '=' . $spelling->cliWrite,
                };
            } else {
                $document = self::place($document, self::segments($writePath), self::parse($spelling->yamlWrite));
            }
        }

        try {
            $observation = $this->process->observe($document, $arguments, $cacheOwned, $observable, $withdraw);
        } catch (ProbeFailure $failure) {
            $this->failures[] = $row->key() . ': ' . $failure->getMessage();

            return new Observation(Observation::CRASHED, 'probe failed: ' . $failure->getMessage());
        }

        return Observation::ofMeasured($observation->outcome(), $observation->text());
    }

    /**
     * @return array{door: Observation, merged: Observation, object: Observation}
     */
    private function write(FormRow $row, string $rule, string $option, ?FormSpelling $spelling, bool $respell = false): array
    {
        if ($spelling === null) {
            return $this->inProcess->take([], [], [], $rule);
        }

        $key = $respell ? self::respell($option) : $option;

        return match ($row->door) {
            'rule-opt' => $this->inProcess->take([], [$rule . ':' . $key . '=' . $spelling->cliWrite], [], $rule),
            'cli-alias' => $this->inProcess->take([], [], [ltrim($row->alias, '-') => $spelling->cliWrite], $rule),
            default => $this->inProcess->take(
                ['rules' => [$rule => self::place([], explode('.', $key), self::parse($spelling->yamlWrite))]],
                [],
                [],
                $rule,
            ),
        };
    }

    /**
     * One cell, judged through the declared observability limit.
     *
     * The limit is asked here rather than inside the classifier's signature at
     * three call sites, and the verdict the cell WOULD have carried without it
     * is recorded beside it: a limit that covers a working observation is
     * refused by {@see Limits::conflicts()}, and that refusal needs the
     * unrestricted verdict to exist.
     */
    private function judge(
        Observation $omitted,
        Observation $value,
        Observation $equivalent,
        ?Observation $collapse,
        string $form,
        FormRow $row,
        bool $witnessed,
        string $cellKey,
    ): Judgement {
        $promised = \in_array($form, $row->promisedForms, true);
        $limitRow = $this->limits()->rowFor($row->door, $row->path, $form);

        if ($limitRow !== null) {
            $this->unrestricted[] = [
                $cellKey,
                Classifier::form($omitted, $value, $equivalent, $collapse, $form, $promised, $row->nullMeans, $witnessed)->verdict,
                $limitRow->kind,
            ];
        }

        return Classifier::form($omitted, $value, $equivalent, $collapse, $form, $promised, $row->nullMeans, $witnessed, $limitRow?->reason);
    }

    /**
     * A write path split into its segments, with `\.` meaning a dot INSIDE a
     * key rather than a step down.
     *
     * A computed metric is named `computed.<something>` by the product's own
     * template, so the one place a key legitimately carries a dot is also the
     * one place axis D writes a placeholder. Splitting such a path naively
     * builds a document two levels deep that the product has never been asked
     * about.
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $segments = preg_split('/(?<!\\\\)\./', $path);

        if ($segments === false) {
            return [$path];
        }

        return array_values(array_map(static fn(string $segment): string => str_replace('\\.', '.', $segment), $segments));
    }

    /**
     * The declared hit for an option leaf, or the empty string when the
     * canonical magnitude is fine. Keyed on the LEAF rather than the whole
     * path: the three framework keys repeat under all 54 producers, and an
     * enumeration of 162 paths would be the same four statements written 162
     * times.
     */
    private function hitFor(string $option): string
    {
        return $this->declarations->axisAHits[self::leafOf($option)] ?? '';
    }

    /**
     * The sensitivity probe needs a writing the ledger calls equivalent to a
     * PROMISED one. Where the ledger promises no form at all — every
     * `unpromised` row — there is nothing to be equivalent to, so the stand
     * searches the eight forms for one the door accepts and that moves the
     * observation, and says so in `decided_by`. Without the search those rows
     * would all read NOT OBSERVABLE by construction, which is a claim about
     * the stand dressed up as a claim about the product.
     *
     * @param array{door: Observation, merged: Observation, object: Observation} $omitted
     */
    private function referenceForm(FormRow $row, string $rule, string $option, array $omitted): ?string
    {
        foreach ($row->promisedForms as $form) {
            if ($form !== 'null') {
                return $form;
            }
        }

        foreach (['int', 'bool', 'string-nonnumber', 'list', 'map'] as $form) {
            $probe = $this->write($row, $rule, $option, $this->declarations->forms[$form]);

            if ($probe['object']->accepted() && $probe['object']->text !== $omitted['object']->text) {
                return $form;
            }
        }

        return null;
    }

    /**
     * Both halves of a pair probe, written into one document, which is what
     * `same-source` means.
     *
     * @param array<string, mixed> $values key => the value chosen for it
     *
     * @return array{door: Observation, merged: Observation, object: Observation}
     */
    private function pairWrite(string $rule, array $values): array
    {
        $options = [];

        /** @var mixed $value */
        foreach ($values as $key => $value) {
            $options = self::place($options, explode('.', rtrim($key, ':')), $value);
        }

        return $this->inProcess->take($options === [] ? [] : ['rules' => [$rule => $options]], [], [], $rule);
    }

    /**
     * One side of a pair, written with a value the product can be seen to read
     * differently from the key being absent.
     *
     * The canonical magnitude is tried first and its observation is the one
     * kept, so the search costs a second probe only where that magnitude turns
     * out to be what the product already does. Where NOTHING declared moves
     * the object the canonical write stands and the observation is stored as
     * it came: the stand does not decide here that the row is unmeasurable —
     * {@see Classifier::pair()} reads that off the four stored sides, so the
     * frozen half is judged by the same rule as the live one.
     *
     * THE SEARCH IS OBSERVATIONAL, AND THAT IS A DECISION. The value written
     * depends on the build under test: a magnitude equal to what the product
     * does anyway is stepped over. Measured on this tree, inside the five
     * rules the shorthand-scope round cures this fires on 71 of 506 sides and
     * on one leaf, `enabled`, choosing between `true` and `false`. The
     * alternative — declaring per leaf which magnitude is inert — puts a
     * second source of truth beside the product's own defaults and goes stale
     * in silence when one changes. It is also what keeps `side_b` safe: a
     * per-side literal that turned out to be a product default would make its
     * side inert and move a cell OUT of defect, and the fallback drops such a
     * side back to the canonical candidate instead.
     *
     * Side A passes null and keeps the canonical magnitude; side B passes
     * {@see Declarations::$sideBLiterals}.
     *
     * @param array<string, string>|null $sideLiterals per-side spellings by form
     *
     * @return array{0: mixed, 1: array{door: Observation, merged: Observation, object: Observation}, 2: string}
     */
    private function pairSide(string $rule, string $key, Observation $omitted, ?array $sideLiterals = null): array
    {
        $written = rtrim($key, ':');
        $candidates = $this->pairCandidates($rule, $written, $sideLiterals);
        // Whether the per-side set actually produced a first value is a
        // property of the SHAPE, not of the argument: `bool` and a closed word
        // set have no third spelling, so side B silently keeps side A's
        // candidates there. Comparing against the plain list is how the label
        // stays true for those keys instead of claiming a per-side write that
        // was never made. Both lists are pure; neither runs the product.
        $perSide = $sideLiterals !== null && $candidates[0] !== $this->pairCandidates($rule, $written)[0];
        $first = $perSide ? 'per-side magnitude' : 'canonical magnitude';
        $canonical = $this->pairWrite($rule, [$written => $candidates[0]]);

        if ($canonical['object']->accepted() && $canonical['object']->text !== $omitted->text) {
            return [$candidates[0], $canonical, $first];
        }

        /** @var mixed $candidate */
        foreach (\array_slice($candidates, 1) as $candidate) {
            $taken = $this->pairWrite($rule, [$written => $candidate]);

            if ($taken['object']->accepted() && $taken['object']->text !== $omitted->text) {
                return [$candidate, $taken, 'alternate magnitude'];
            }
        }

        return [$candidates[0], $canonical, $first . ', which no declared value here can improve on'];
    }

    /**
     * The ordered values one pair member may be written with: the per-side
     * magnitude where one is declared and the shape accepts it, then the
     * canonical magnitude of that shape, then the declared alternates.
     *
     * @param array<string, string>|null $sideLiterals see {@see self::pairSide()}
     *
     * @return non-empty-list<mixed>
     */
    private function pairCandidates(string $rule, string $key, ?array $sideLiterals = null): array
    {
        // A block key — `callable:`, `class:` — is written as the map of every
        // leaf the ledger names under it for this rule, so the block carries a
        // real effect rather than an empty mapping that means "omitted".
        $children = [];

        foreach ($this->ledger->pairs as $pair) {
            if ($pair->rule !== $rule) {
                continue;
            }

            foreach ([$pair->keyA, $pair->keyB] as $candidate) {
                if (str_starts_with($candidate, $key . '.') && !str_contains(substr($candidate, \strlen($key) + 1), '.')) {
                    $children[] = substr($candidate, \strlen($key) + 1);
                }
            }
        }

        // 66 wrote `class: {max_warning, max_error}`, not the whole block: a
        // block carrying `threshold` BESIDE a band key is a mix the product
        // refuses, and a probe refused on its own write would read MISCOMPOSED
        // for the stand's reason, not the product's.
        $children = array_values(array_unique($children));
        $bands = array_diff($children, ['threshold']);

        if ($bands !== []) {
            $children = array_values($bands);
        }

        if ($children === []) {
            return $this->effectWritesFor($rule, $key, $sideLiterals);
        }

        $perChild = [];

        foreach ($children as $child) {
            // Each child gets its own shape's magnitudes: a block whose
            // `enabled` leaf was written with the band magnitude is refused
            // for the stand's spelling, which is the defect S8 removed from
            // leaf keys and this branch used to keep.
            $perChild[$child] = $this->effectWritesFor($rule, $key . '.' . $child, $sideLiterals);
        }

        return self::zip($perChild);
    }

    /**
     * The ordered values one key may be written with, taken from the shape
     * declared for it rather than from the spelling of its own name.
     *
     * The key set that answers is found through exactly the same two-depth
     * walk {@see CrossCheck::resolveKey()} already asks of axis A: the rule's
     * own declaration, or — when the head segment names a level slot — that
     * slot's own. A key nothing declares is not a guess this stand makes on
     * its behalf: it is a `LedgerError`, because a silent fallback here would
     * reproduce the defect S8 removes (an int written under a text/list/bool
     * key, read as a composition failure that was really a form mismatch).
     *
     * A key the class recognises only to answer about ITSELF —
     * `RuleOptionKeySet::alsoAnsweredByTheClass()`, `knows()` true and
     * `shapeOf()` null — carries no general form to search: measured against
     * every `same-source` pair in the ledger, this is exactly two rules.
     * `UnassignedClassOptions` accepts `enabled: false` as "leave things as
     * they are" and refuses `enabled: true` outright; `LayerViolationOptions`
     * refuses its three removed severity keys for ANY value at all. `false`
     * is therefore the write this stand asks for the whole bucket: it is the
     * "leave things as they are" spelling the vocabulary itself documents for
     * this state, not a guess read off the key's spelling — the two real
     * occurrences (`architecture.unassigned-class.enabled`, and the three
     * `architecture.layer-violation` removed-severity keys) are accepted and
     * refused respectively either way, because their answer does not depend
     * on the value at all.
     *
     * `$sideLiterals` is the ONE argument axis E must never pass. Both of its
     * call sites are in {@see self::neighbourWrite()} and leave it null, so
     * this method answers there exactly what it answered before the per-side
     * magnitude existed — a cell moving on axis E would be a defect of the
     * round that added it rather than a consequence of it.
     *
     * @param array<string, string>|null $sideLiterals see {@see self::pairSide()}
     *
     * @return non-empty-list<mixed>
     */
    private function effectWritesFor(string $rule, string $key, ?array $sideLiterals = null): array
    {
        $class = $this->inProcess->optionsClasses[$rule] ?? null;

        if ($class === null || !is_a($class, RuleOptionsInterface::class, true)) {
            throw new LedgerError('pair probe: "' . $rule . '" is not a registered rule with an options class');
        }

        $segments = explode('.', $key);
        $slots = is_a($class, HierarchicalRuleOptionsInterface::class, true) ? $class::levelOptionsClasses() : [];
        $head = ConfigKeySpelling::normalize($segments[0]);

        if (\count($segments) > 1 && isset($slots[$head])) {
            $set = $slots[$head]::acceptedOptionKeys();
            $normalized = ConfigKeySpelling::normalize(implode('.', \array_slice($segments, 1)));
        } else {
            $set = $class::acceptedOptionKeys();
            $normalized = ConfigKeySpelling::normalize($key);
        }

        if (!$set->knows($normalized)) {
            throw new LedgerError(
                'pair probe: "' . $rule . '.' . $key . '" — no declaration recognises the key normalized as "' . $normalized . '"',
            );
        }

        $shape = $set->shapeOf($normalized);

        if ($shape === null) {
            return [false];
        }

        $writes = [];

        // FIRST, so the side that declares one writes it. A shape with no
        // per-side spelling — `bool`, or a closed set of words — answers null
        // here and the list is the one side A gets. `$leafKey` is deliberately
        // not passed: the enum fallback inside `writeForShape()` would hand an
        // enum key the SAME word both sides already use.
        if ($sideLiterals !== null) {
            /** @var mixed $perSide */
            $perSide = $this->writeForShape($shape, $sideLiterals, null);

            if ($perSide !== null) {
                $writes[] = $perSide;
            }
        }

        $writes[] = $this->writeForShape($shape, $this->literals(null), $rule . '.' . $key, $key);
        $leaf = $this->declarations->leafAlternates[self::leafOf($key)] ?? null;

        if ($leaf !== null) {
            /** @var mixed $declared */
            $declared = self::parse($leaf);

            if (!$shape->matches($declared)) {
                throw new LedgerError(
                    'effect-magnitudes.tsv declares "' . $leaf . '" for the leaf "' . self::leafOf($key)
                    . '", which the shape of "' . $rule . '.' . $key . '" refuses',
                );
            }

            $writes[] = $declared;
        }

        /** @var mixed $alternate */
        $alternate = $this->writeForShape($shape, $this->literals($this->declarations->formAlternates), null);

        if ($alternate !== null) {
            $writes[] = $alternate;
        }

        $unique = [];

        /** @var mixed $write */
        foreach ($writes as $write) {
            if (!\in_array($write, $unique, true)) {
                $unique[] = $write;
            }
        }

        return $unique;
    }

    /**
     * The literal each form is written with: `forms.tsv`'s own spelling, or
     * that form's counter-default alternate where one is declared. Forms the
     * alternate table does not name are dropped rather than defaulted — a
     * fallback to the canonical spelling would hand the search the value it
     * has already been told does not move the object.
     *
     * @param array<string, string>|null $alternates
     *
     * @return array<string, string>
     */
    private function literals(?array $alternates): array
    {
        if ($alternates === null) {
            return array_map(static fn(FormSpelling $form): string => $form->yamlWrite, $this->declarations->forms);
        }

        return $alternates;
    }

    /**
     * The eight declared forms of {@see Declarations::$forms}, asked of the
     * shape in the shape's own words — the same question
     * {@see CrossCheck::accepts()} asks for axis A, with the container
     * offered filled when the magnitude on its own does not match
     * (`listOf(nonEmptyText())` refuses `[7331]` while still being a list).
     *
     * The forms are walked in `forms.tsv` order for BOTH magnitude sets, so a
     * shape answers with the same form whichever set it is asked with, and the
     * alternate is a second value of that form rather than a second form.
     *
     * @param array<string, string> $literals form name => the spelling to write
     * @param string|null $subject the key to name when nothing matches, or null to answer null instead
     */
    private function writeForShape(RuleOptionShape $shape, array $literals, ?string $subject, ?string $leafKey = null): mixed
    {
        foreach ($this->declarations->formNames() as $form) {
            if ($form === 'null' || !isset($literals[$form])) {
                continue;
            }

            /** @var mixed $candidate */
            $candidate = self::parse($literals[$form]);

            if ($shape->matches($candidate)) {
                return $candidate;
            }
        }

        foreach ($this->declarations->formNames() as $form) {
            if ($form === 'null' || $form === 'list' || $form === 'map' || !isset($literals[$form])) {
                continue;
            }

            /** @var mixed $scalar */
            $scalar = self::parse($literals[$form]);

            if ($shape->matches([$scalar])) {
                return [$scalar];
            }

            if ($shape->matches(['a' => $scalar])) {
                return ['a' => $scalar];
            }
        }

        // A closed set of words accepts none of the eight canonical forms by
        // construction: `all`, `any`, `error` are not 7331 in any spelling. The
        // stand already declares real values for such leaves in
        // `effect-magnitudes.tsv`, and before this it threw before looking at
        // them -- so a key could not declare its word set without stopping the
        // run. The declared alternate is consulted here, and only a leaf with
        // no declaration at all is still a LedgerError.
        if ($leafKey !== null) {
            $declaredLeaf = $this->declarations->leafAlternates[self::leafOf($leafKey)] ?? null;

            if ($declaredLeaf !== null) {
                /** @var mixed $alternate */
                $alternate = self::parse($declaredLeaf);

                if ($shape->matches($alternate)) {
                    return $alternate;
                }
            }
        }

        if ($subject === null) {
            return null;
        }

        throw new LedgerError(
            'pair probe: the declared shape "' . $shape->describe() . '" of "' . $subject
            . '" accepts none of the eight forms this stand can write, and '
            . ($leafKey === null ? 'no leaf was named' : 'effect-magnitudes.tsv declares nothing for its leaf'),
        );
    }

    private static function leafOf(string $key): string
    {
        return str_contains($key, '.') ? substr($key, (int) strrpos($key, '.') + 1) : $key;
    }

    /**
     * Per-key candidate lists turned into candidate DOCUMENTS: the first entry
     * writes every key its own first candidate, the second entry every key its
     * second one, and a key with fewer candidates repeats its last. One
     * document per rank rather than the product of all of them — the question
     * asked of a block or a neighbour is whether IT carries an effect, not
     * which of its leaves does.
     *
     * @param non-empty-array<string, non-empty-list<mixed>> $perKey
     *
     * @return non-empty-list<array<string, mixed>>
     */
    private static function zip(array $perKey): array
    {
        $depth = max(array_map(\count(...), $perKey));
        $documents = [self::rank($perKey, 0)];

        for ($rank = 1; $rank < $depth; ++$rank) {
            $document = self::rank($perKey, $rank);

            if (!\in_array($document, $documents, true)) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * @param non-empty-array<string, non-empty-list<mixed>> $perKey
     *
     * @return array<string, mixed>
     */
    private static function rank(array $perKey, int $rank): array
    {
        $document = [];

        foreach ($perKey as $key => $candidates) {
            /** @var mixed $value */
            $value = $candidates[min($rank, \count($candidates) - 1)];
            $document[$key] = $value;
        }

        return $document;
    }

    /**
     * Which cells the ledger owes, without taking a single probe.
     *
     * This is what makes a freshness check affordable: the expensive run
     * proves the product's behaviour, and the cheap one proves the grid still
     * spans the ledger. The two are held together by an assertion inside the
     * expensive run — the multiset below must equal the keys it actually
     * emitted — so this reconstruction cannot drift away from the generators
     * it mirrors without the next full run saying so.
     *
     * A count rather than a set even though every key is now distinct: the
     * pair key carries `kind`, which is what split the nineteen `same-source`
     * pairs that used to share one. Keeping the multiset is the guard against
     * a twentieth collision appearing under some other column — comparing
     * sets would fold it away in silence, which is exactly how this one lived.
     *
     * @return array<string, int> cell key => how many ledger rows produce it
     */
    public function expectedKeys(): array
    {
        $flags = self::table($this->root . '/promise-effect/cli-root-flags.tsv', 5);
        $forms = $this->declarations->formNames();
        $keys = [];

        foreach ($this->ledger->forms as $row) {
            $whole = str_starts_with($row->path, 'rules.')
                ? $this->split($row->path) === null
                : $row->door === 'cli-root' && !isset($flags[$row->path]);

            if ($whole) {
                $keys[$row->key()] = ($keys[$row->key()] ?? 0) + 1;

                continue;
            }

            foreach ($forms as $form) {
                $keys[$row->key() . '|' . $form] = ($keys[$row->key() . '|' . $form] ?? 0) + 1;
            }
        }

        foreach ($this->ledger->pairs as $row) {
            if ($row->sourceScope !== 'same-source') {
                continue;
            }

            $keys[$row->key()] = ($keys[$row->key()] ?? 0) + 1;
        }

        foreach ($this->compositionKeys() as $key) {
            $keys[$key] = ($keys[$key] ?? 0) + 1;
        }

        foreach ($this->neighbourhood()->rows as $row) {
            $keys[$row->key()] = ($keys[$row->key()] ?? 0) + 1;
        }

        ksort($keys);

        return $keys;
    }

    private function record(string $axis, string $key, string $side, Observation $observation): void
    {
        $this->raw[] = [$axis, $key, $side, $observation->outcome, $observation->text];
    }

    /** @return array{0: string, 1: string}|null */
    private function split(string $path): ?array
    {
        $rest = substr($path, \strlen('rules.'));
        $best = null;

        foreach (array_keys($this->inProcess->optionsClasses) as $producer) {
            if (str_starts_with($rest, $producer . '.') && ($best === null || \strlen($producer) > \strlen($best))) {
                $best = $producer;
            }
        }

        return $best === null ? null : [$best, substr($rest, \strlen($best) + 1)];
    }

    /**
     * snake = camel = kebab, as the ledger's C3 carrier declares. The spelling
     * is produced here as text and never asked of the product: asking the
     * product which spellings it treats alike would make the equivalence a
     * measurement of itself.
     */
    private static function respell(string $option): string
    {
        $segments = explode('.', $option);

        foreach ($segments as $index => $segment) {
            if (str_contains($segment, '_')) {
                $segments[$index] = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $segment))));

                continue;
            }

            if (str_contains($segment, '-')) {
                $segments[$index] = str_replace('-', '_', $segment);

                continue;
            }

            if (preg_match('/[A-Z]/', $segment) === 1) {
                $segments[$index] = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $segment));
            }
        }

        return implode('.', $segments);
    }

    /**
     * @param array<array-key, mixed> $document
     * @param list<string> $segments
     *
     * @return array<array-key, mixed>
     */
    private static function place(array $document, array $segments, mixed $value): array
    {
        $head = array_shift($segments);

        if ($head === null) {
            return $document;
        }

        $key = ctype_digit($head) ? (int) $head : $head;

        if ($segments === []) {
            $document[$key] = $value;

            return $document;
        }

        /** @var mixed $child */
        $child = $document[$key] ?? [];
        $document[$key] = self::place(\is_array($child) ? $child : [], $segments, $value);

        return $document;
    }

    private static function parse(string $yaml): mixed
    {
        return \Symfony\Component\Yaml\Yaml::parse($yaml);
    }

    /**
     * The shallowest point at which the written value stopped being
     * distinguishable from an omitted key. Not a verdict — a location, and the
     * only thing the three cheap points are for.
     *
     * @param array{door: Observation, merged: Observation, object: Observation} $omitted
     * @param array{door: Observation, merged: Observation, object: Observation} $value
     */
    private static function lostAt(string $door, array $omitted, array $value): string
    {
        // A CLI door does not pass through the merged document at all: its
        // value travels beside it as a CLI override. Naming that point as the
        // place a CLI form was lost would be a location that does not exist.
        $points = $door === 'yaml'
            ? ['door' => 'door', 'merged' => 'mergedDocument', 'object' => 'optionsObject']
            : ['door' => 'door', 'object' => 'optionsObject'];

        foreach ($points as $point => $name) {
            if ($value[$point]->text === $omitted[$point]->text) {
                return $name;
            }
        }

        return 'nowhere';
    }

    /** @return array<string, list<string>> */
    private static function table(string $path, int $width): array
    {
        $lines = file($path, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . $path);
        }

        $rows = [];
        $seenHeader = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$seenHeader) {
                $seenHeader = true;

                continue;
            }

            $cells = array_pad(explode("\t", $line), $width, '');
            $rows[$cells[0]] = $cells;
        }

        return $rows;
    }
}
