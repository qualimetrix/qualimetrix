<?php

declare(strict_types=1);

/**
 * One rule from (ledger row, probe triple, reachability witness) to a verdict.
 *
 * One classifier judges both halves of the before/after pair, which is why the
 * frozen snapshot stores RAW observations and not verdicts: freezing verdicts
 * would let a later edit of this file mean the two halves were judged by
 * different rules without anything saying so.
 *
 * Not knowing yields the worse verdict. `OK` is the only verdict that requires
 * evidence of two things at once — the promise met AND the producer reachable —
 * and it is never awarded by default.
 */

namespace Qualimetrix\PromiseEffect;

/**
 * How an axis-C observation is compared against the two sides of its dispute.
 *
 * `Leaves` is the only member the run ever uses. The other two exist so a
 * control can show what the round's own warnings are about: each is a
 * comparison this stand could plausibly have been written with, and each
 * silently renames one verdict into a neighbouring one. A control that could
 * only call the real comparison could never show either substitution without
 * breaking the product.
 */
enum CompositionComparison
{
    /** Leaf by leaf, sibling loss first. What the run uses. */
    case Leaves;

    /**
     * Whole texts instead of leaves. A merge that assembled the value
     * element by element equals neither side as TEXT, and a stand comparing
     * texts has nowhere to put that but "the other side won" — `FRANKENSTEIN`
     * read as `MISLAYERED`.
     */
    case WholeText;

    /**
     * Winner equality asked before sibling loss. When the middle layer of a
     * triple evicts a slot only the lowest layer wrote and the highest layer
     * does not write it back, the result equals the highest layer leaf for
     * leaf — so asking "did the promised side win" first reads a lost sibling
     * as a correct composition.
     */
    case WinnerBeforeSibling;
}

final class Verdict
{
    public const string OK = 'OK';
    public const string INERT = 'INERT';
    public const string COLLAPSED = 'COLLAPSED';
    public const string REFUSES = 'REFUSES';
    public const string MALFORMED = 'MALFORMED';
    public const string NOT_OBSERVABLE = 'NOT OBSERVABLE';
    public const string UNPROMISED = 'UNPROMISED';

    public const string COEXISTENCE_OK = 'COEXISTENCE_OK';
    public const string MISCOMPOSED = 'MISCOMPOSED';

    // Axis C: which WRITER of one path won. `MISLAYERED` and
    // `COMPOSITION_REFUSED` are the two ways the promised layer order can be
    // contradicted; `LOST_SIBLING` and `FRANKENSTEIN` are two outcomes a
    // four-name vocabulary would have folded into `MISLAYERED` — a slot only
    // the lower layer wrote vanishing, and a value assembled leaf by leaf that
    // equals neither side. Both are named because both are what the
    // denominator counted the triples for.
    public const string COMPOSED_AS_PROMISED = 'COMPOSED_AS_PROMISED';
    public const string MISLAYERED = 'MISLAYERED';
    public const string LOST_SIBLING = 'LOST_SIBLING';
    public const string COMPOSITION_REFUSED = 'COMPOSITION_REFUSED';
    public const string FRANKENSTEIN = 'FRANKENSTEIN';

    // Axis E: the form of one key BESIDE a neighbour. The claim is narrow and
    // it is about the neighbour: writing `~` must be indistinguishable from
    // leaving the key out, so whatever the neighbour did alone it must still
    // do with `~` written next to it.
    public const string PRESENCE_NEUTRAL = 'PRESENCE_NEUTRAL';
    public const string PRESENCE_SWITCHED_BRANCH = 'PRESENCE_SWITCHED_BRANCH';
    public const string PRESENCE_REFUSED = 'PRESENCE_REFUSED';

    /**
     * Verdicts that are always a defect of the product, not of the stand.
     *
     * `REFUSES` is not among them, and it is not always innocent either: a
     * refusal of a form the ledger PROMISED is exactly the class this round
     * measures. The seven labels stay seven — the label says what was
     * observed — and whether the observation contradicts the promise travels
     * as {@see Judgement::$defect} beside it.
     */
    public const array DEFECTS = [self::INERT, self::COLLAPSED, self::MALFORMED, self::UNPROMISED];
}

final readonly class Judgement
{
    public function __construct(
        public string $verdict,
        public string $decidedBy,
        public bool $defect = false,
    ) {}
}

final class Classifier
{
    /**
     * The reason an axis-C cell carries when the probe could not tell its two
     * sides apart.
     *
     * A constant rather than a sentence written twice, because the run counts
     * these cells: 02-stand.md asks for "distinguishable, yes or no" to be
     * PRINTED for every pair, and a summary that recognised the state by
     * matching prose would silently stop counting the day the prose changed.
     */
    public const string SIDES_ALIKE = 'onlyLow == onlyHigh: the probe does not tell the sides apart';

    /**
     * @param bool $promised the ledger names this form among the promised ones
     * @param bool $witnessed the producer behind this path was seen to run
     */
    public static function form(
        Observation $omitted,
        Observation $value,
        Observation $equivalent,
        ?Observation $collapse,
        string $form,
        bool $promised,
        string $nullMeans,
        bool $witnessed,
        ?string $limit = null,
    ): Judgement {
        // The observability limit comes FIRST, before even a crash, because it
        // says the question was never put to the product: a CLI door has no
        // spelling for this form, or the canonical magnitude names nothing
        // this key could act on. Judging such a cell on what came back would
        // report the stand's own spelling as the product's behaviour — 238
        // cells of axis A did exactly that. Declared in
        // `promise-effect/observability-limits.tsv` and read here, so both
        // halves of the pair are judged by one rule.
        if ($limit !== null) {
            return new Judgement(Verdict::NOT_OBSERVABLE, $limit);
        }

        // A refusal the omitted probe already carried, word for word, is the
        // ENVELOPE's and not the leaf's: writing the key changed nothing about
        // what came back, so nothing was asked about its form. Axis D found
        // this the expensive way — every `computed_metrics.<name>.*` probe was
        // written under a metric name the product rejects before it reaches
        // any leaf, and eleven rows reported the name error as a verdict on
        // the leaf. Judged here rather than re-spelled away, so it holds on
        // both halves and on every envelope, not only the one that was caught.
        if ($value->outcome === $omitted->outcome
            && $value->text === $omitted->text
            && \in_array($value->outcome, [Observation::REFUSED_FRAMED, Observation::REFUSED_UNFRAMED], true)) {
            return new Judgement(Verdict::NOT_OBSERVABLE, 'the envelope is refused with or without this key');
        }

        if ($value->outcome === Observation::CRASHED) {
            return new Judgement(Verdict::MALFORMED, 'crashed', true);
        }

        if ($value->outcome === Observation::REFUSED_UNFRAMED) {
            return new Judgement(Verdict::MALFORMED, 'refused without the product framing', true);
        }

        if ($value->outcome === Observation::REFUSED_FRAMED) {
            // A framed refusal of a form the ledger promised contradicts the
            // promise as squarely as silence does. `~` under
            // `null_means=default` is the same case: the carrier says it means
            // "take the default", and a refusal is not that.
            $owed = $promised || ($form === 'null' && $nullMeans === 'default');

            return new Judgement(Verdict::REFUSES, $owed ? 'refused a form it promised' : 'refused', $owed);
        }

        // The sensitivity question comes before every judgement about effect:
        // a stand that cannot tell the canonical write from an omitted key
        // cannot tell anything else about this row either.
        if ($omitted->text === $equivalent->text) {
            return new Judgement(Verdict::NOT_OBSERVABLE, 'omitted == equivalent');
        }

        if ($form === 'null') {
            return self::nullForm($omitted, $value, $collapse, $nullMeans, $witnessed);
        }

        if ($value->text === $omitted->text) {
            return new Judgement(Verdict::INERT, $promised ? 'accepted and did nothing' : 'accepted an unpromised form and did nothing', true);
        }

        if ($collapse !== null && $collapse->accepted() && $value->text === $collapse->text) {
            return new Judgement(Verdict::COLLAPSED, 'equal to the canonical write of another form', true);
        }

        if (!$promised) {
            // Accepted where the ledger required a refusal, and the declared
            // coercion target did not name what it became. Worst verdict:
            // the value was taken as something its own form does not name, and
            // the stand cannot say as what.
            return new Judgement(Verdict::COLLAPSED, 'accepted an unpromised form, target unnamed', true);
        }

        if (!$witnessed) {
            return new Judgement(Verdict::NOT_OBSERVABLE, 'no reachability witness for the producer');
        }

        return new Judgement(Verdict::OK, 'effect distinguishable and the producer reachable');
    }

    private static function nullForm(
        Observation $omitted,
        Observation $value,
        ?Observation $collapse,
        string $nullMeans,
        bool $witnessed,
    ): Judgement {
        if ($nullMeans === 'unpromised') {
            return new Judgement(Verdict::NOT_OBSERVABLE, 'the ledger promises nothing about `~` here');
        }

        if ($nullMeans === 'addressed-answer') {
            // The class exists to answer for this key in its own words; both
            // silence and a generic refusal are the defect.
            return new Judgement(Verdict::INERT, 'accepted silently where the class owes an answer of its own', true);
        }

        if ($nullMeans === 'refuse') {
            return new Judgement(
                $value->text === $omitted->text ? Verdict::INERT : Verdict::COLLAPSED,
                'accepted `~` where the ledger promised a refusal',
                true,
            );
        }

        // null_means=default: `~` must mean exactly what an omitted key means.
        return self::defaultingValue($omitted, $value, $collapse, $witnessed);
    }

    /**
     * `~` judged as a value that must default.
     *
     * This once served two ledger values: `default`, and a narrower
     * `default-value-present-key` for keys inside `rules:`, where writing a
     * key with no value still chose the rule's flat form. X19 removed that
     * distinction from the product -- branch selection now asks whether a
     * value was written, not whether a key was -- so the carrier dropped the
     * carve-out, the ledger rows moved to `default`, and the second branch
     * here became unreachable and went with them.
     */
    private static function defaultingValue(
        Observation $omitted,
        Observation $value,
        ?Observation $collapse,
        bool $witnessed,
    ): Judgement {
        if ($value->text === $omitted->text) {
            return $witnessed
                ? new Judgement(Verdict::OK, '`~` behaved as an omitted key')
                : new Judgement(Verdict::NOT_OBSERVABLE, 'no reachability witness for the producer');
        }

        if ($collapse !== null && $collapse->accepted() && $value->text === $collapse->text) {
            return new Judgement(Verdict::COLLAPSED, '`~` equal to the canonical write of another form', true);
        }

        return new Judgement(Verdict::COLLAPSED, '`~` did something other than defaulting', true);
    }

    /**
     * The pair probe is a different question and gets its own two verdicts:
     * "promised a refusal, composed anyway" is neither INERT nor COLLAPSED.
     */
    public static function pair(
        Observation $omitted,
        Observation $onlyA,
        Observation $onlyB,
        Observation $both,
        string $coexistence,
    ): Judgement {
        if (str_starts_with($coexistence, 'one-wins:')) {
            $winner = substr($coexistence, \strlen('one-wins:'));
            $expected = $winner === 'a' ? $onlyA : $onlyB;

            // Which key won is a question about telling them apart. Two sides
            // that render the same — because the winner's own write is what
            // the product does anyway, or because both keys were written the
            // same value — make `both === expected` true whatever won.
            if ($onlyA->text === $onlyB->text || $expected->text === $omitted->text) {
                return new Judgement(Verdict::NOT_OBSERVABLE, 'the two sides render the same, so nothing here could say which key won');
            }

            return $both->text === $expected->text
                ? new Judgement(Verdict::COEXISTENCE_OK, 'the promised key won')
                : new Judgement(Verdict::MISCOMPOSED, 'a different key won', true);
        }

        if ($coexistence === 'refuse') {
            return $both->outcome === Observation::REFUSED_FRAMED
                ? new Judgement(Verdict::COEXISTENCE_OK, 'refused, as promised')
                : new Judgement(Verdict::MISCOMPOSED, 'composed where the ledger promised a refusal: ' . $both->outcome, true);
        }

        if (!$both->accepted()) {
            return new Judgement(Verdict::MISCOMPOSED, 'refused where the ledger promised composition: ' . $both->outcome, true);
        }

        if (!$onlyA->accepted() || !$onlyB->accepted()) {
            // Neither side alone is writable, so "both applied" has no meaning
            // here. Worst verdict, and the reason travels with it.
            return new Judgement(Verdict::MISCOMPOSED, 'one half alone is not writable: ' . $onlyA->outcome . '/' . $onlyB->outcome, true);
        }

        $lost = [];
        $inert = [];

        foreach (['A' => $onlyA, 'B' => $onlyB] as $side => $alone) {
            // A side whose own write leaves the object exactly as an omitted
            // key does has no effect, so "its effect survived" is true of every
            // possible merge. Counting that as composition is the vacuum this
            // gate exists to name — measured once at 117 of 117 `enabled`
            // sides, where the canonical `true` is the product's own default.
            if ($alone->text === $omitted->text) {
                $inert[] = $side;

                continue;
            }

            if (!self::effectSurvives($omitted->text, $alone->text, $both->text)) {
                $lost[] = $side;
            }
        }

        // Loss before inertness, and the order is the whole point: a row where
        // A is inert and B is genuinely dropped is a real defect, and a gate
        // asked first would hide it behind the half that measures nothing.
        if ($lost !== []) {
            return new Judgement(Verdict::MISCOMPOSED, 'the effect of ' . implode(' and ', $lost) . ' is absent when both are written', true);
        }

        if ($inert !== []) {
            return new Judgement(
                Verdict::NOT_OBSERVABLE,
                implode(' and ', $inert) . ' alone leaves the object as an omitted key does, so no survival could be asked of it',
            );
        }

        return new Judgement(Verdict::COEXISTENCE_OK, 'both effects present');
    }

    /**
     * The axis-C rule: of the two (or three) layers that wrote one path, whose
     * value is in the result, and does that match the promise.
     *
     * The order of the questions carries as much as the questions do, and it
     * is the reverse of the obvious one:
     *
     *   1. can the sides be told apart at all — the sensitivity gate;
     *   2. was the whole thing refused;
     *   3. did a slot only ONE side wrote disappear;
     *   4. and only then, which side's value is in the result.
     *
     * Step 3 before step 4 is not stylistic. A middle layer that evicts the
     * lowest layer's exclusive slot leaves a result identical to the highest
     * layer's, leaf for leaf, so a winner-first reading calls it a correct
     * composition and the triples measure nothing. {@see CompositionComparison}
     * carries both wrong orders so a control can show the difference.
     *
     * @param string $promised `low`, `high`, `refuse` or `unpromised`, out of
     *                         the ledger's own sixth column
     * @param bool $highRewritesEveryLowKey when true the sides dispute the
     *                                      same keys, so there is no slot for the low side
     *                                      to lose and the sibling question is not asked
     */
    public static function composition(
        Observation $omitted,
        Observation $low,
        Observation $high,
        Observation $both,
        string $promised,
        bool $highRewritesEveryLowKey = false,
        CompositionComparison $how = CompositionComparison::Leaves,
    ): Judgement {
        // A side that cannot be written is a question about the DOOR, which
        // axis A owns. "Both applied" has no meaning when one of them was
        // never applied on its own, and calling it a composition defect would
        // publish a form fact under a composition label.
        if (!$low->accepted() || !$high->accepted()) {
            return new Judgement(
                Verdict::NOT_OBSERVABLE,
                'one side alone is not writable: ' . $low->outcome . '/' . $high->outcome,
            );
        }

        // The sensitivity gate of this axis, and the reason the two magnitudes
        // are declared rather than assumed: a probe whose two sides render
        // identically cannot say whose value survived, whatever came back.
        if ($low->text === $high->text) {
            return new Judgement(Verdict::NOT_OBSERVABLE, self::SIDES_ALIKE);
        }

        // Unpromised rows are the ledger's silence, not the product's fault.
        // The label still says what happened — that is what stage 03 counts —
        // and `defect` stays false because no promise was contradicted.
        $silent = $promised === 'unpromised';

        if (!$both->accepted()) {
            return $promised === 'refuse'
                ? new Judgement(Verdict::COMPOSED_AS_PROMISED, 'refused, as promised')
                : new Judgement(Verdict::COMPOSITION_REFUSED, 'refused where nothing promised a refusal: ' . $both->outcome, !$silent);
        }

        // Accepted where a refusal was promised: whichever label the
        // comparison below lands on, the promise is contradicted.
        $defect = $promised === 'refuse' || !$silent;

        if (!$highRewritesEveryLowKey && $how !== CompositionComparison::WinnerBeforeSibling) {
            $lost = self::lostSibling($omitted, $low, $high, $both);

            if ($lost !== '') {
                // `promised_survival=lost` would be a promise the loss KEEPS.
                // No row carries it: the round tried that reading and a fixture
                // that could tell a middle layer's value from the constructor
                // default refuted it. What is lost is not the lowest layer's
                // value -- the shorthand did overwrite that -- but the MIDDLE
                // layer's, in the half the top layer never rewrote.
                return new Judgement(Verdict::LOST_SIBLING, $lost, $promised === 'lost' ? false : $defect);
            }
        }

        $same = $how === CompositionComparison::WholeText
            ? static fn(Observation $side): bool => $both->text === $side->text
            : static fn(Observation $side): bool => self::sameLeaves($side->text, $both->text);

        foreach (['high' => $high, 'low' => $low] as $side => $observation) {
            if (!$same($observation)) {
                continue;
            }

            return $promised === $side
                ? new Judgement(Verdict::COMPOSED_AS_PROMISED, 'the promised side won')
                : new Judgement(Verdict::MISLAYERED, 'the ' . $side . ' side won', $defect);
        }

        if ($how === CompositionComparison::WholeText) {
            // The substitution the round warns about, spelled out: a text
            // comparison has no name for "equal to neither", so the only
            // reading left to it is that the other side won.
            return new Judgement(Verdict::MISLAYERED, 'equal to neither side as TEXT', $defect);
        }

        return new Judgement(Verdict::FRANKENSTEIN, 'accepted and equal to neither side leaf for leaf', $defect);
    }

    /**
     * A slot exactly one side wrote, and which is back to its unwritten value
     * once both are written.
     *
     * Both directions are asked even though the triples only stake the lower
     * one: a slot the HIGHER layer alone wrote and that vanished is the same
     * defect seen from the other end, and refusing to look at it would be a
     * claim about which half of the mechanism is allowed to break.
     *
     * @return string the reason, or the empty string when no slot was lost
     */
    private static function lostSibling(Observation $omitted, Observation $low, Observation $high, Observation $both): string
    {
        $before = self::leaves($omitted->text);
        $together = self::leaves($both->text);

        foreach (['low' => [$low, $high], 'high' => [$high, $low]] as $side => [$writer, $other]) {
            $written = self::leaves($writer->text);
            $untouched = self::leaves($other->text);

            foreach ($written as $pointer => $leaf) {
                $unwritten = $before[$pointer] ?? null;

                // Exclusive to this side: this side moved the slot, the other
                // side left it exactly as an unwritten key leaves it.
                if ($unwritten === null || $unwritten === $leaf || ($untouched[$pointer] ?? null) !== $unwritten) {
                    continue;
                }

                if (($together[$pointer] ?? null) === $unwritten) {
                    return 'the slot ' . $pointer . ', written by the ' . $side . ' side alone, is back to its unwritten value';
                }
            }
        }

        return '';
    }

    /**
     * Whether two observations carry the same value at every leaf.
     *
     * Sorted before comparing, because PHP's `===` on arrays is order
     * sensitive and the order here is the product's own insertion order: a
     * value assembled by merging two layers can carry the same leaves as a
     * value written by one of them and still render them in a different
     * sequence. Comparing unsorted would report that as "equal to neither
     * side" — the FRANKENSTEIN this axis exists to detect, produced by the
     * stand rather than by the product.
     */
    private static function sameLeaves(string $left, string $right): bool
    {
        $leftLeaves = self::leaves($left);
        $rightLeaves = self::leaves($right);
        ksort($leftLeaves);
        ksort($rightLeaves);

        return $leftLeaves === $rightLeaves;
    }

    /**
     * The axis-E rule: `~` written BESIDE a neighbour must be worth exactly
     * as much as the key not being there.
     *
     * The comparison is against the neighbour ALONE, never against the empty
     * document: what is claimed is that the presence of the key changes
     * nothing about how its neighbour is read. Two gates come first, and both
     * of them hand the row to another axis rather than judging it here — a
     * neighbour with no visible effect has nothing to lose, and a `~` that
     * already moves the object on its own is a defect axis A owns, which this
     * axis would otherwise count a second time.
     */
    public static function neighbourhood(
        Observation $omitted,
        Observation $neighbour,
        Observation $nullAlone,
        Observation $both,
    ): Judgement {
        if (!$neighbour->accepted()) {
            return new Judgement(Verdict::NOT_OBSERVABLE, 'the neighbour alone is refused: ' . $neighbour->outcome);
        }

        if ($neighbour->text === $omitted->text) {
            return new Judgement(Verdict::NOT_OBSERVABLE, 'the neighbour alone changes nothing, so it has no effect to lose');
        }

        if (!$nullAlone->accepted() || $nullAlone->text !== $omitted->text) {
            return new Judgement(Verdict::NOT_OBSERVABLE, '`~` alone is already not an omitted key here, which axis A owns');
        }

        if (!$both->accepted()) {
            return new Judgement(Verdict::PRESENCE_REFUSED, 'refused only because `~` stands beside the neighbour: ' . $both->outcome, true);
        }

        return $both->text === $neighbour->text
            ? new Judgement(Verdict::PRESENCE_NEUTRAL, '`~` beside the neighbour changed nothing')
            : new Judgement(Verdict::PRESENCE_SWITCHED_BRANCH, '`~` beside the neighbour changed how it is read', true);
    }

    /**
     * Whether everything one key changed on its own is still changed when both
     * are written. Compared leaf by leaf rather than as whole texts: two keys
     * that compose correctly produce a text equal to neither side alone.
     */
    private static function effectSurvives(string $omitted, string $alone, string $both): bool
    {
        $before = self::leaves($omitted);
        $single = self::leaves($alone);
        $together = self::leaves($both);

        foreach ($single as $pointer => $leaf) {
            if (($before[$pointer] ?? null) === $leaf) {
                continue;
            }

            if (!\array_key_exists($pointer, $together) || $together[$pointer] !== $leaf) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private static function leaves(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        $out = [];
        self::walk($decoded, '', $out);

        return $out;
    }

    /** @param array<string, string> $out */
    private static function walk(mixed $node, string $pointer, array &$out): void
    {
        if (!\is_array($node)) {
            $encoded = json_encode($node);
            $out[$pointer] = $encoded === false ? 'null' : $encoded;

            return;
        }

        if ($node === []) {
            $out[$pointer] = '[]';

            return;
        }

        foreach ($node as $key => $child) {
            self::walk($child, $pointer . '/' . $key, $out);
        }
    }
}
