<?php

declare(strict_types=1);

/**
 * The plantings: one per verdict, one per side of the four sets, one per
 * probe this package had to fix, and one per population.
 *
 * Every verdict case edits a RAW observation of the frozen half, which is to
 * say it simulates the product behaving differently at the boundary the
 * classifier reads. That is the strongest cheap control available: the stand's
 * own measurement code is not stubbed, the ledger is not rewritten, and the
 * question asked is exactly "would this verdict have been produced, and only
 * for this cell". The ledger case (`L1`) is the one exception, and it exists
 * so that "the ledger is actually consulted" is proved too.
 *
 * What these controls do NOT prove is that the process probes measure the
 * right thing. They prove the rule from observation to verdict, and the floor
 * of `promise-effect/floor.tsv` is what holds the measurement side.
 */

namespace Qualimetrix\PromiseEffectControls;

final readonly class ControlCase
{
    /**
     * @param list<array{string, string, string, string}> $raw row key, side, field (`outcome`|`text`), value;
     *                                                         a value of `@<side>` copies that side's text of the same row, and `@<side>@<row>` of another row
     * @param list<array{string, int, string}> $ledger a ledger line matched by its prefix, a column index, the new cell
     * @param array<string, string> $expected cell key => the `VERDICT|defect` it must become
     */
    public function __construct(
        public string $id,
        public string $verdict,
        public string $intent,
        public array $raw,
        public array $ledger,
        public array $expected,
    ) {}
}

/**
 * A control on something that is not the rule from observation to verdict: a
 * probe, or the floor as each half of the pair asks it.
 *
 * The nine verdict cases recompute over the frozen raw observations, which is
 * why they are cheap — and why they are blind to everything that happens
 * before an observation is stored. Two of this package's three fixes live
 * exactly there: the refusal-framing pair is two process runs, and the worker
 * decision is an extraction from a log file whose contaminated digest the
 * frozen half cannot un-eat. Each case below states its own assertions; the
 * runner holds them, because they are not a planting into a cell map.
 */
final readonly class ProbeCase
{
    public function __construct(
        public string $id,
        public string $subject,
        public string $intent,
    ) {}
}

/**
 * A planting into one of the two sides the four sets compare.
 *
 * The declaration side is planted IN MEMORY, not into a copied tree: a copy
 * resolves PSR-4 back through `vendor/` into the original `src/`, so a planted
 * options class is loaded from the tree it was meant to replace. That false
 * green has bitten this repository before, and the bound is stated rather than
 * risked — this case proves the comparison sees a changed declaration, not
 * that it would see one changed in `src/`.
 */
final readonly class CrossCase
{
    /**
     * @param list<array{string, int, string}> $ledger a ledger line matched by its prefix, a column index, the new cell
     * @param array{string, string, string}|null $shape rule, key, and the shape factory to plant instead
     * @param list<string> $added bucket|cell keys the planting must introduce
     * @param list<string> $removed bucket|cell keys it must take away
     * @param list<string> $absent bucket|cell keys the UNPLANTED comparison must not carry
     */
    public function __construct(
        public string $id,
        public string $side,
        public string $intent,
        public array $ledger,
        public ?array $shape,
        public array $added,
        public array $removed,
        public array $absent = [],
    ) {}
}

/** A planting against the population guard rather than the classifier. */
final readonly class GuardCase
{
    /**
     * @param array{string, string} $file a path relative to the copied tree, and its content
     */
    public function __construct(
        public string $id,
        public string $population,
        public string $intent,
        public array $file,
        public string $expected,
    ) {}
}

/**
 * A planting into the axis-C or axis-E rule itself.
 *
 * These cannot be planted the way the nine verdict cases are. A verdict case
 * edits a RAW observation of the frozen half and names the grid cell it must
 * move; a case here carries its own fixture and names no cell, because two of
 * them are not about an observation at all: they are about the COMPARISON, and
 * the only way to show that comparing whole texts renames `FRANKENSTEIN` into
 * `MISLAYERED` is to run both comparisons over one fixture.
 *
 * So each case carries a fixture and TWO readings of it: the `baseline`, which
 * differs from the planted one in exactly one observation, and the planted
 * one. "Reddens exactly its own case" means here that the one cell the fixture
 * describes moved from the baseline verdict to the declared one, and that the
 * two are not the same verdict — a case whose planting changed nothing would
 * otherwise pass by agreeing with itself.
 */
final readonly class JudgementCase
{
    /**
     * @param array<string, string> $sides side name => `outcome|text`, where a bare text means `accepted`
     * @param array{string, string} $planted the side this case replaces, and its new `outcome|text`
     * @param array<string, string> $substitutions the name of a wrong comparison => the verdict it would have produced instead
     */
    public function __construct(
        public string $id,
        public string $axis,
        public string $verdict,
        public bool $defect,
        public string $baselineVerdict,
        public string $intent,
        public array $sides,
        public array $planted,
        public string $promised = '',
        public bool $highRewritesEveryLowKey = false,
        public array $substitutions = [],
    ) {}
}

final class Cases
{
    /** The cell every form case works on: promised, witnessed, accepted, with a comparand recorded. */
    private const string FORM = 'form|yaml|rules.coupling.class-rank.error|float';

    /**
     * An axis-D cell the product currently coerces into another form — the one
     * case that must become OK. It used to be `form|yaml|exclude|list`, which
     * stopped being a usable baseline the moment the declared hit was applied
     * to the reference form's own write: that cell is `OK` unplanted now, and
     * a case whose expectation is already true proves nothing.
     */
    private const string ROOT = 'form|cli-root|include_generated|bool';

    /** @return list<ControlCase> */
    public static function verdicts(): array
    {
        return [
            new ControlCase(
                'V1',
                'INERT',
                'the product starts ignoring a form it promised to honour',
                [[self::FORM, 'value', 'text', '@omitted@form|yaml|rules.coupling.class-rank.error']],
                [],
                [self::FORM => 'INERT|yes'],
            ),
            new ControlCase(
                'V2',
                'COLLAPSED',
                'the product starts coercing the form into the canonical write of another',
                [[self::FORM, 'value', 'text', '@collapse']],
                [],
                [self::FORM => 'COLLAPSED|yes'],
            ),
            new ControlCase(
                'V3',
                'REFUSES',
                'the product refuses, with its own framing, a form the ledger promised',
                [[self::FORM, 'value', 'outcome', 'refused-framed']],
                [],
                // The label says REFUSES and the defect column says yes: this
                // is the pair the round exists to keep apart, and a control
                // that only compared labels would not see it.
                [self::FORM => 'REFUSES|yes'],
            ),
            new ControlCase(
                'V4',
                'MALFORMED',
                'the refusal loses the product framing',
                [[self::FORM, 'value', 'outcome', 'refused-unframed']],
                [],
                [self::FORM => 'MALFORMED|yes'],
            ),
            new ControlCase(
                'V5',
                'NOT OBSERVABLE',
                'the stand stops being able to tell the canonical write from an omitted key',
                [[
                    'form|rule-opt|rules.coupling.distance.max-distance-error',
                    'equivalent',
                    'text',
                    '@omitted',
                ]],
                [],
                // Two cells of this row are decided after the sensitivity
                // check. Of the other six, three are refusals and three are
                // covered by the CLI door's own limit; both are settled before
                // the sensitivity question, so neither can witness it. It was
                // one until `coupling.distance` gained a reachability witness —
                // `int` and `float` had been held at NOT OBSERVABLE by the
                // missing witness, which is the later test, so the sensitivity
                // planting could not move them.
                //
                // `bool` was the third until `b9fd87d3` (the declared shape of
                // a rule option value): a rule-opt door now REFUSES a boolean
                // where it used to coerce it, and the baseline this case is
                // measured against is the product that already carries that
                // cure. The cell is not lost — it is judged before the rule
                // this case is about, so it cannot witness it.
                [
                    'form|rule-opt|rules.coupling.distance.max-distance-error|float' => 'NOT OBSERVABLE|no',
                    'form|rule-opt|rules.coupling.distance.max-distance-error|int' => 'NOT OBSERVABLE|no',
                ],
            ),
            new ControlCase(
                'V6',
                'OK',
                'a coerced form starts carrying an effect of its own on a witnessed producer',
                [[self::ROOT, 'value', 'text', 'shape=plantedab01']],
                [],
                [self::ROOT => 'OK|no'],
            ),
            new ControlCase(
                'V7',
                'UNPROMISED',
                'the grid grows a cell the ledger never promised',
                [['form|yaml|rules.planted.invented.threshold|int', 'value', 'text', '{"planted":true}']],
                [],
                ['form|yaml|rules.planted.invented.threshold|int' => 'UNPROMISED|yes'],
            ),
            new ControlCase(
                'P1',
                'COEXISTENCE_OK',
                'the product starts refusing, framed, where the ledger promised a refusal',
                // It used to plant into `architecture.layer-violation`'s
                // superseded-key pair. That pair answered `refused-unframed`
                // on the pre-cure product, so planting the framing moved it;
                // on the baseline this case is now measured against it already
                // answers `refused-framed` — `b9fd87d3` rewrote that very
                // refusal along with the rest of `LayerViolationOptions` — and
                // a planting that writes what is already there proves nothing.
                // The pair below is promised a refusal
                // by the ledger and composes anyway, so the planting still has
                // somewhere to move the cell FROM.
                [[
                    'pair|complexity.ccn|callable.threshold|threshold|same-source|2-same-name-top-vs-level',
                    'both',
                    'outcome',
                    'refused-framed',
                ]],
                [],
                ['pair|complexity.ccn|callable.threshold|threshold|same-source|2-same-name-top-vs-level' => 'COEXISTENCE_OK|no'],
            ),
            new ControlCase(
                'P2',
                'MISCOMPOSED',
                'two keys that composed stop composing: writing both loses what each did alone',
                // The pair has to be one where each key alone MOVES the
                // object: `both := omitted` can only lose an effect that was
                // there. The old subject was a `6-gate` pair of
                // `architecture.circular-dependency`, whose four sides are
                // identical on this baseline — neither key moves the object,
                // so the planting wrote what was already there. Both keys of
                // the pair below move it, so the planting loses two effects
                // and the verdict names them.
                [[
                    'pair|code-smell.boolean-argument|allowed-prefixes|flag-promoted-properties|same-source|6-subject-vs-filter',
                    'both',
                    'text',
                    '@omitted',
                ]],
                [],
                ['pair|code-smell.boolean-argument|allowed-prefixes|flag-promoted-properties|same-source|6-subject-vs-filter' => 'MISCOMPOSED|yes'],
            ),
            new ControlCase(
                'X1',
                'MALFORMED',
                'an exit code above the fail-on gate stops being read as an observed value',
                // `fail_on` is the one row whose probe withdraws
                // `--fail-on=none`, so it is the only place exit 2 is
                // reachable. Unplanted it reads OK: `~` behaves as an omitted
                // key, both runs ending above the gate. Planted back into an
                // unframed refusal — which is how the stand read exit 2 before
                // this package — the cell is a MALFORMED defect again.
                [
                    ['form|yaml|fail_on|null', 'value', 'outcome', 'refused-unframed'],
                    ['form|yaml|fail_on|null', 'value', 'text', 'exit=3 {"error":"planted"}'],
                ],
                [],
                ['form|yaml|fail_on|null' => 'MALFORMED|yes'],
            ),
            new ControlCase(
                'L1',
                'COLLAPSED',
                'the ledger stops promising a form the product still accepts and moves',
                [],
                // Column 3 of a `form` row is `promised_forms`. Dropping
                // `float` from it must turn a cell that was OK into a
                // collapse, which is what proves the ledger is read at all
                // rather than the observations being judged on their own.
                [['form	yaml	rules.coupling.class-rank.error	', 3, 'null,int']],
                [self::FORM => 'COLLAPSED|yes'],
            ),
        ];
    }

    /**
     * The axis-C and axis-E plantings.
     *
     * Every verdict either axis can produce is named by at least one case, on
     * both sides of the move: the baseline reading and the planted one. The
     * two substitution cases are the ones 03-grid.md asks for by name — each
     * points at a comparison this stand could have been written with, and says
     * which verdict that comparison would have silently produced instead.
     *
     * @return list<JudgementCase>
     */
    public static function judgements(): array
    {
        // One scalar path, both layers writing the same key. The shape of
        // every `composition-path` row.
        $scalar = [
            'omitted' => '{"options":{"warning":20}}',
            'low' => '{"options":{"warning":4211}}',
            'high' => '{"options":{"warning":8623}}',
            'both' => '{"options":{"warning":8623}}',
        ];

        // A triple: the lowest layer writes the graduated pair, the highest
        // writes one slot of it back, and `warning` is disputed by nobody.
        $triple = [
            'omitted' => '{"options":{"warning":20,"error":30}}',
            'low' => '{"options":{"warning":4211,"error":4212}}',
            'high' => '{"options":{"warning":20,"error":8624}}',
            'both' => '{"options":{"warning":4211,"error":8624}}',
        ];

        // A framework key, observed through the two predicates. Both layers
        // write the same key, and a merge that kept both patterns is a value
        // equal to neither side.
        $framework = [
            'omitted' => '{"excluded":{"path:src/Low/Helper.php":false,"path:src/High/Helper.php":false}}',
            'low' => '{"excluded":{"path:src/Low/Helper.php":true,"path:src/High/Helper.php":false}}',
            'high' => '{"excluded":{"path:src/Low/Helper.php":false,"path:src/High/Helper.php":true}}',
            'both' => '{"excluded":{"path:src/Low/Helper.php":false,"path:src/High/Helper.php":true}}',
        ];

        $neighbour = [
            'omitted' => '{"options":{"class":{"warning":14,"error":20}}}',
            'neighbour' => '{"options":{"class":{"warning":4211,"error":8623}}}',
            'nullAlone' => '{"options":{"class":{"warning":14,"error":20}}}',
            'both' => '{"options":{"class":{"warning":4211,"error":8623}}}',
        ];

        return [
            new JudgementCase(
                'CP1',
                'C',
                'MISLAYERED',
                true,
                'COMPOSED_AS_PROMISED',
                'the lower layer\'s value is the one in the object, where the carrier promised the higher one',
                $scalar,
                ['both', '{"options":{"warning":4211}}'],
                'high',
                true,
            ),
            new JudgementCase(
                'CP2',
                'C',
                'LOST_SIBLING',
                true,
                'FRANKENSTEIN',
                'the slot only the lowest layer wrote is back to its unwritten value, and the result reads as the highest layer\'s',
                $triple,
                ['both', '{"options":{"warning":20,"error":8624}}'],
                'high',
                false,
                // The substitution that matters more than the famous one: ask
                // "did the promised side win" before "did a slot vanish" and
                // this exact cell reports a correct composition.
                ['WinnerBeforeSibling' => 'COMPOSED_AS_PROMISED'],
            ),
            new JudgementCase(
                'CP3',
                'C',
                'COMPOSITION_REFUSED',
                true,
                'COMPOSED_AS_PROMISED',
                'the two layers together are refused, and no carrier promised a refusal',
                $scalar,
                ['both', 'refused-framed|Cannot mix "threshold" with "warning"/"error".'],
                'high',
                true,
            ),
            new JudgementCase(
                'CP4',
                'C',
                'FRANKENSTEIN',
                true,
                'COMPOSED_AS_PROMISED',
                'the merge kept both layers\' patterns, so the value equals neither side',
                $framework,
                ['both', '{"excluded":{"path:src/Low/Helper.php":true,"path:src/High/Helper.php":true}}'],
                'high',
                true,
                // The substitution 03-grid.md names: whole texts instead of
                // leaves. A text comparison has no word for "equal to
                // neither", so it reports the other side winning.
                ['WholeText' => 'MISLAYERED'],
            ),
            new JudgementCase(
                'CP5',
                'C',
                'NOT OBSERVABLE',
                false,
                'COMPOSED_AS_PROMISED',
                'the two magnitudes render the same, so nothing the run came back with could say whose value survived',
                $scalar,
                ['high', '{"options":{"warning":4211}}'],
                'high',
                true,
            ),
            new JudgementCase(
                'CP6',
                'C',
                'COMPOSED_AS_PROMISED',
                false,
                'FRANKENSTEIN',
                'the promised side won although the product rendered its leaves in a different order — which is what tells a leaf comparison from a text one in the RUN, not only in a control',
                [
                    'omitted' => '{"options":{"warning":20,"error":30}}',
                    'low' => '{"options":{"warning":4211,"error":30}}',
                    'high' => '{"options":{"warning":20,"error":8624}}',
                    'both' => '{"options":{"warning":20,"error":30}}',
                ],
                ['both', '{"options":{"error":8624,"warning":20}}'],
                'high',
                true,
                // The same cell under the substitution: a text comparison sees
                // two different strings and has no word for it but "the other
                // side won".
                ['WholeText' => 'MISLAYERED'],
            ),
            new JudgementCase(
                'NB1',
                'E',
                'PRESENCE_SWITCHED_BRANCH',
                true,
                'PRESENCE_NEUTRAL',
                '`~` beside a level block throws the block away, although `~` alone is worth an omitted key',
                $neighbour,
                ['both', '{"options":{"class":{"warning":14,"error":20}}}'],
            ),
            new JudgementCase(
                'NB2',
                'E',
                'PRESENCE_REFUSED',
                true,
                'PRESENCE_NEUTRAL',
                'a document lawful without the key is refused once `~` stands beside its neighbour',
                $neighbour,
                ['both', 'refused-framed|Cannot mix "threshold" with "warning"/"error".'],
            ),
            new JudgementCase(
                'NB3',
                'E',
                'NOT OBSERVABLE',
                false,
                'PRESENCE_NEUTRAL',
                'a `~` that already moves the object alone belongs to axis A, and this axis must not count it a second time',
                $neighbour,
                ['nullAlone', '{"options":{"class":{"warning":7331,"error":20}}}'],
            ),
        ];
    }

    /** @return list<ProbeCase> */
    public static function probes(): array
    {
        return [
            new ProbeCase(
                'B1',
                'refusal framing',
                'the two framing probes still answer as the REFUSES verdict is defined, and the judgement on them reddens when either changes',
            ),
            new ProbeCase(
                'PL1',
                'composition plan',
                'the plan says whether the higher layers rewrite every key the lowest one wrote — true where two layers dispute one key, false for a triple whose top layer writes a strict subset',
            ),
            new ProbeCase(
                'B2',
                'worker decision',
                'the logfile observable carries the worker decision and nothing of the run that took it',
            ),
            new ProbeCase(
                'N1',
                'limit consumed',
                'a cell the observability limit covers is judged anyway — the stand reporting its own spelling as the product\'s behaviour',
            ),
            new ProbeCase(
                'N2',
                'limit over an effect',
                'a limit declared where the door DOES express the form, over a cell whose effect the stand observes',
            ),
            new ProbeCase(
                'F3',
                'floor, withdrawn',
                'a row declared withdrawn reddens when it is still defective, when the grid observes it at all, and when the grid is blind for another reason — while a row that leaves the floor with no disposition reddens as it always did',
            ),
            new ProbeCase(
                'N3',
                'limit over a crash',
                'a limit covering a crash no uncovered form of the same row publishes reddens, and one whose row does publish it does not',
            ),
            new ProbeCase(
                'N4',
                'limit over coercion',
                'a limit claiming the answer is about the value, declared over a cell whose value was coerced into another form, reddens',
            ),
            new ProbeCase(
                'N5',
                'limit basis',
                'the two door kinds are refused against the door definition in both directions: array-valued under `door-cannot-express`, and everything else under `stand-writes-one-where-the-door-repeats`',
            ),
            new ProbeCase(
                'S1',
                'declared path',
                'a path declared in one of the P1 set\'s hand-typed constants that no file stands at is refused by name, and the real constants name no such path',
            ),
            new ProbeCase(
                'F1',
                'floor, frozen half',
                'on REAL observations a classifier that puts a cured floor row back among the defects reddens, and names that row',
            ),
            new ProbeCase(
                'F2',
                'floor, live grid',
                'on the live grid a floor row that leaves the floor undeclared reddens, and so does one declared cured that did not move',
            ),
        ];
    }

    /** @return list<CrossCase> */
    public static function crossChecks(): array
    {
        return [
            new CrossCase(
                'C1',
                'registry',
                'a form the registry stops promising appears on the declaration side of the comparison',
                // Column 3 of a `form` row is `promised_forms`. Dropping
                // `float` there leaves the declaration (`number()->orNull()`)
                // accepting a form nothing promises — the WIDER half, which is
                // the half the four sets exist to show.
                [['form	yaml	rules.coupling.class-rank.error	', 3, 'null,int']],
                null,
                ['WIDER|form|yaml|rules.coupling.class-rank.error|float'],
                [],
            ),
            new CrossCase(
                'C3',
                'door',
                'the comparison folds each form through its own door, and not through one door for all three',
                [],
                null,
                [],
                [],
                // The round's worked example. `integer()->orNull()` is one
                // declaration serving three doors: in YAML `warning: "5"` is a
                // string and is refused, while on a CLI door `5` is the only
                // way to type a number at all and is folded back to one. A
                // comparison that read every door as YAML would put this cell
                // in `ledger \ declaration` — on 205 paths at once, and with
                // both halves its own fault rather than the product's.
                [
                    'LEDGER_ONLY|form|rule-opt|rules.design.dit.warning|string-number',
                    'LEDGER_ONLY|form|cli-alias|rules.design.dit.warning|string-number',
                    'WIDER|form|yaml|rules.design.dit.warning|string-number',
                ],
            ),
            new CrossCase(
                'C2',
                'declaration',
                'a declaration that narrows appears on the registry side of the comparison',
                [],
                // `number()->orNull()` narrowed to `integer()->orNull()`: the
                // fractional form the registry promises at the two doors that
                // promise it becomes unaccepted, and the third door — an alias
                // whose row promises nothing — loses a WIDER line instead.
                // One plant, three cells, each in a different bucket: a
                // comparison blind to the door would move them together.
                ['coupling.class-rank', 'error', 'integer'],
                [
                    'LEDGER_ONLY|form|yaml|rules.coupling.class-rank.error|float',
                    'LEDGER_ONLY|form|rule-opt|rules.coupling.class-rank.error|float',
                ],
                ['WIDER_UNOPPOSED|form|cli-alias|rules.coupling.class-rank.error|float'],
            ),
        ];
    }

    /** @return list<GuardCase> */
    public static function guards(): array
    {
        return [
            new GuardCase(
                'G1',
                'producer',
                'a new rule appears in the source and the grid does not carry it',
                [
                    'src/Planted/PlantedRule.php',
                    "<?php\n\nnamespace Planted;\n\nfinal class PlantedRule\n{\n"
                        . "    public const string NAME = 'planted.probe';\n}\n",
                ],
                'producer planted.probe',
            ),
            new GuardCase(
                'G2',
                'config-path',
                'a new path outside `rules:` is inventoried and the grid does not carry it',
                [
                    'docs/internal/plans/promise-effect/measurement/config-paths.tsv',
                    "planted_root\tplanted_root\tboth\t--planted-root\tPlanted\t—\t—\t—\t—\tUNENUMERATED\t—\t—\n",
                ],
                'config-path planted_root',
            ),
            new GuardCase(
                'G3',
                'same-source-pair',
                'a new interacting key pair is inventoried and the grid does not carry it',
                [
                    'docs/internal/plans/promise-effect/measurement/key-pairs.tsv',
                    "complexity.ccn\tPlantedOptions\t(top)\tplanted-a\tplanted-b\t1-threshold-group-declared\tcompose\tplanted\n",
                ],
                'same-source-pair complexity.ccn|planted-a|planted-b|1-threshold-group-declared',
            ),
            new GuardCase(
                'G4',
                'options-class',
                'a new options class appears in the source and no producer the grid carries owns it',
                [
                    'src/Planted/PlantedOptions.php',
                    "<?php\n\nnamespace Planted;\n\nfinal class PlantedOptions implements RuleOptionsInterface\n{\n}\n",
                ],
                'options-class Planted\\PlantedOptions',
            ),
        ];
    }
}
