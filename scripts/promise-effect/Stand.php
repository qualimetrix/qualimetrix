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

    /** The value every pair probe writes on the A side; the B side gets the next one. */
    private const int PAIR_SEED = 7331;

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
            $a = $this->pairWrite($rule, [$row->keyA => self::PAIR_SEED]);
            $b = $this->pairWrite($rule, [$row->keyB => self::PAIR_SEED + 1]);
            $both = $this->pairWrite($rule, [$row->keyA => self::PAIR_SEED, $row->keyB => self::PAIR_SEED + 1]);

            $coexistence = $row->coexistence;

            if (str_starts_with($coexistence, 'one-wins:')) {
                $winner = substr($coexistence, \strlen('one-wins:'));
                $coexistence = 'one-wins:' . ($winner === $row->keyA ? 'a' : 'b');
            }

            $judgement = Classifier::pair($omitted['object'], $a['object'], $b['object'], $both['object'], $coexistence);
            $cells[] = new Cell('B', $row->key(), 'both', 'optionsObject', $judgement->verdict, $judgement->decidedBy, $row->status, $judgement->defect);

            $this->record('B', $row->key(), 'omitted', $omitted['object']);
            $this->record('B', $row->key(), 'onlyA', $a['object']);
            $this->record('B', $row->key(), 'onlyB', $b['object']);
            $this->record('B', $row->key(), 'both', $both['object']);
        }

        return $cells;
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
        $leaf = str_contains($option, '.') ? substr($option, (int) strrpos($option, '.') + 1) : $option;

        return $this->declarations->axisAHits[$leaf] ?? '';
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
     * `same-source` means. The value at each key is searched rather than
     * assumed: a pair row names two keys and says nothing about their forms.
     *
     * @param array<string, int> $keys
     *
     * @return array{door: Observation, merged: Observation, object: Observation}
     */
    private function pairWrite(string $rule, array $keys): array
    {
        $options = [];

        foreach ($keys as $key => $seed) {
            $options = self::place($options, explode('.', rtrim($key, ':')), $this->pairValue($rule, rtrim($key, ':'), $seed));
        }

        return $this->inProcess->take($options === [] ? [] : ['rules' => [$rule => $options]], [], [], $rule);
    }

    private function pairValue(string $rule, string $key, int $seed): mixed
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
                    $children[substr($candidate, \strlen($key) + 1)] = $seed;
                }
            }
        }

        if ($children !== []) {
            // 66 wrote `class: {max_warning, max_error}`, not the whole block:
            // a block carrying `threshold` BESIDE a band key is a mix the
            // product refuses, and a probe refused on its own write would read
            // MISCOMPOSED for the stand's reason, not the product's.
            $bands = array_diff(array_keys($children), ['threshold']);

            if ($bands !== []) {
                unset($children['threshold']);
            }

            return $children;
        }

        if (str_ends_with(strtolower($key), 'enabled')) {
            return false;
        }

        return $seed;
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
