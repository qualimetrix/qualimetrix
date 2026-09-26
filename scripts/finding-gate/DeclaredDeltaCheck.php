<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A surface that differs, held to the delta declared for it and the field moves licensed inside it — or,
 * while deriving, measured into the declaration instead — and every declaration that nothing performed.
 */
final class DeclaredDeltaCheck
{
    /**
     * Surface key => measured diff, while deriving the declared delta instead of
     * holding the run to it.
     *
     * @var array<string, string>|null
     */
    private ?array $derived = null;

    public function __construct(
        private readonly Options $options,
        private readonly GateReport $report,
        private readonly DeclaredDelta $declaredDelta,
        private readonly DeclaredFieldMoves $declaredFieldMoves,
        private readonly ChannelSplit $split,
    ) {}

    public function startDeriving(): void
    {
        $this->derived = [];
    }

    /** @return list<string> the files written */
    public function rewriteDerived(): array
    {
        return $this->declaredDelta->rewrite($this->derived ?? []);
    }

    /**
     * A surface that still differs after maps and normalization: recorded while
     * deriving, otherwise held to its declaration, or a mismatch when it has none.
     */
    public function checkDifference(string $key, string $left, string $right): void
    {
        $diff = ExactDiff::between($left, $right, 'candidate', 'reference (mapped)');

        if ($this->derived !== null) {
            $this->derived[$key] = $diff->render();

            return;
        }

        $declared = $this->declaredDelta->claim($key);

        if ($declared === null) {
            $this->report->fail(
                FailureClass::SURFACE_MISMATCH,
                $key,
                'The surface differs beyond what the declared maps and normalization account for.',
                Diff::between($left, $right, 'candidate', 'reference (mapped)'),
            );

            return;
        }

        $this->checkAgainstDeclaredDelta($key, $diff, $declared);
    }

    /**
     * Holds a differing surface to its declaration, on all four properties.
     *
     * Size and reach are judged on the MEASURED diff, not on the declared text:
     * a declaration that reaches too far must fail for reaching too far, and not
     * be excused by also failing to match.
     */
    private function checkAgainstDeclaredDelta(string $key, ExactDiff $diff, string $declared): void
    {
        if ($diff->changedLineCount() > DeclaredDelta::MAX_CHANGED_LINES) {
            $this->report->fail(
                FailureClass::DELTA_TOO_LARGE,
                $key,
                \sprintf(
                    'The measured diff is %d changed line(s), and a declaration may be %d. Declare the rename as map'
                    . ' rows instead of dropping in a blob.',
                    $diff->changedLineCount(),
                    DeclaredDelta::MAX_CHANGED_LINES,
                ),
            );
        }

        foreach ($this->overreachingLines($key, $diff) as $problem) {
            $this->report->fail(
                FailureClass::DELTA_OVERREACH,
                $key,
                $problem . ' A declared delta may change a compared field only inside a record whose (rule, code)'
                . ' pair a declared split already explains, or where a ' . DeclaredFieldMoves::INDEX . ' row names'
                . ' this exact pair of values on this exact surface — the waiver normalization was refused is'
                . ' refused here too.',
            );
        }

        if ($diff->render() === $declared) {
            return;
        }

        $this->report->fail(
            FailureClass::DELTA_MISMATCH,
            $key,
            \sprintf(
                'The measured diff is not the declared one (%s). Re-derive it with --derive-declared-delta and review'
                . ' what moved.',
                $this->declaredDelta->fileOf($key),
            ),
            [
                ...Diff::between($declared, $diff->render(), 'declared delta', 'measured diff'),
                ...$diff->tokenDetail(),
            ],
        );
    }

    /**
     * The diff lines that change a field the equivalence tuple compares.
     *
     * Changed, not mentioned: a compact JSON record names `channel` on the same
     * line as the magnitude it records, so "the line contains a compared field"
     * would flag every such line. Which surfaces a field can be found on, under
     * which key and in which syntax, is {@see PublishedVocabulary} — and it is
     * asked per surface rather than once, because the same field is `message` on
     * one surface, `text` on the next and `description` on the third. For the
     * surfaces that mark no field at all the record-level split check is the
     * guard, and that list is enumerated there rather than assumed here.
     *
     * Two sources of permission, and they answer different questions. A
     * declared split says "this record was renamed, so its fields moved with
     * it"; a {@see DeclaredFieldMoves} row says "this exact value became that
     * exact value on this exact surface, and here is why". The second exists
     * because the first can only ever speak about `channel`, `rule` and `code`
     * — the fields a channel rename rewrites — so every other compared field
     * was unlicensable by construction rather than by judgement.
     *
     * @return list<string>
     */
    private function overreachingLines(string $key, ExactDiff $diff): array
    {
        $fields = EquivalenceTuple::load($this->options->candidateRoot)->fields;
        $problems = [];

        $surface = Surfaces::surfaceClass($key);

        foreach ($diff->pairs() as $index => [$candidateLine, $referenceLine]) {
            foreach ($fields as $field) {
                $onCandidate = PublishedVocabulary::valuesOn($surface, $candidateLine, $field);
                $onReference = PublishedVocabulary::valuesOn($surface, $referenceLine, $field);

                if ($onCandidate === $onReference) {
                    continue;
                }

                // A line that publishes a different *number* of values for a
                // compared field is not a rename of anything: the record set on
                // that line changed, and no declared split can account for it.
                if (\count($onCandidate) !== \count($onReference)) {
                    $problems[] = \sprintf(
                        'Hunk line %d publishes %d value(s) of the compared field "%s" where the reference publishes'
                        . ' %d, so the change is not a rename a declared split could explain.',
                        $index + 1,
                        \count($onCandidate),
                        $field,
                        \count($onReference),
                    );

                    continue;
                }

                // Paired by position within the line, the same principle
                // ExactDiff::pairs() uses across the hunk: a payload publishes
                // its records in one order on both sides, so the n-th value of a
                // field on one line answers the n-th on the other. Asking about
                // the pair rather than about each value separately is what keeps
                // a delta from moving a compared field between two values no
                // explained record ever paired.
                foreach ($onReference as $position => $referenceValue) {
                    $candidateValue = $onCandidate[$position];

                    if ($referenceValue === $candidateValue) {
                        continue;
                    }

                    if ($this->split->allowsMove($field, $referenceValue, $candidateValue)) {
                        continue;
                    }

                    if ($this->declaredFieldMoves->allows($key, $field, $referenceValue, $candidateValue)) {
                        continue;
                    }

                    $problems[] = \sprintf(
                        'Hunk line %d changes the compared field "%s" ("%s" -> "%s"), a move neither a declared'
                        . ' split nor a licensed field move explains.',
                        $index + 1,
                        $field,
                        $referenceValue,
                        $candidateValue,
                    );
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * A surface a declared delta covers that turned out to be equal.
     *
     * The same lie as a stale map row: a declaration of a change nobody can
     * point at.
     */
    public function checkStaleDeclaredDelta(): void
    {
        if ($this->derived !== null) {
            return;
        }

        foreach ($this->declaredDelta->staleSurfaces() as $surface) {
            $this->report->fail(
                FailureClass::DELTA_STALE,
                $surface,
                'A delta is declared for this surface, and the two trees agree on it. A declaration of a change that'
                . ' did not happen fails until it is corrected or removed.',
            );
        }
    }

    /**
     * A licensed move no diff line performed.
     *
     * The same lie as a stale map row, and it has to fail the same way: a row
     * here is the one declaration that lets a compared field differ, so one
     * that describes nothing is a permission sitting in the tree waiting for
     * some later step's diff to walk into it.
     *
     * Skipped while deriving for the reason the declared delta's staleness is:
     * a derive run absorbs every differing surface instead of judging it, so no
     * row can be credited and all of them would read as stale.
     */
    public function checkStaleFieldMoves(): void
    {
        if ($this->derived !== null) {
            return;
        }

        foreach ($this->declaredFieldMoves->staleMoves() as $stale) {
            $this->report->fail(
                FailureClass::FIELD_MOVE_STALE,
                $stale['surface'],
                \sprintf(
                    'The move of %s is licensed on this surface and no diff line performed it. A licence for a'
                    . ' change that did not happen fails until it is corrected or removed.',
                    $stale['move'],
                ),
            );
        }
    }
}
