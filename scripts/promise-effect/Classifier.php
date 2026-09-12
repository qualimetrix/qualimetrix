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

        // null_means=default-value-present-key is the promise's own name for a
        // narrower claim than `default`: PRESENCE of the key, `~` included,
        // still selects the rule's flat form and suppresses a sibling level
        // block (`callable:`/`class:`/`namespace:`) beside it. This branch
        // judges only the OTHER half of that promise — the key's OWN VALUE,
        // which the carrier says still takes the default, through the SAME
        // comparison `default` uses below. The "presence suppresses the
        // sibling" half has no probe here — it needs a document that writes a
        // block BESIDE the key, which is the "form x neighbourhood"
        // coordinate, a different package's input. A green cell from this
        // branch says nothing about that half either way.
        if ($nullMeans === 'default-value-present-key') {
            return self::defaultingValue($omitted, $value, $collapse, $witnessed);
        }

        // null_means=default: `~` must mean exactly what an omitted key means.
        return self::defaultingValue($omitted, $value, $collapse, $witnessed);
    }

    /**
     * `~` judged as a value that must default: the comparison
     * `null_means=default` and the value half of
     * `null_means=default-value-present-key` share, word for word. Extracted
     * so the two carriers are provably the SAME rule rather than two texts
     * that happen to agree today.
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

        foreach (['A' => $onlyA, 'B' => $onlyB] as $side => $alone) {
            if (!self::effectSurvives($omitted->text, $alone->text, $both->text)) {
                $lost[] = $side;
            }
        }

        return $lost === []
            ? new Judgement(Verdict::COEXISTENCE_OK, 'both effects present')
            : new Judgement(Verdict::MISCOMPOSED, 'the effect of ' . implode(' and ', $lost) . ' is absent when both are written', true);
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
