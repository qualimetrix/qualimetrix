<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A surface that differs, held to the delta declared for it and the field moves licensed inside it — or,
 * while deriving, measured into the declaration instead — and every declaration that nothing performed.
 */
final class DeclaredDeltaCheck implements Derivation
{
    /**
     * Surface key => measured diff, while deriving the declared delta instead of
     * holding the run to it.
     *
     * @var array<string, string>|null
     */
    private ?array $derived = null;

    /** @var array<string,true> */
    private array $unexpressible = [];

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
        if (!$this->report->canDerive() || $this->derived === null) {
            return [];
        }
        $derived = array_diff_key($this->derived, $this->unexpressible);
        foreach ($this->report->raised() as $failure) {
            if (!\in_array($failure['class'], [FailureClass::PATH_LEAK, FailureClass::NONDETERMINISM_UNDECLARED, FailureClass::CASE_OUTCOME_MISMATCH], true)) {
                continue;
            }
            foreach (array_keys($derived) as $intent) {
                if (str_contains($failure['scope'], $intent) || (str_contains($intent, '|') && str_contains($failure['scope'], explode('|', $intent)[0]))) {
                    unset($derived[$intent]);
                }
            }
        }
        return $derived === [] ? [] : $this->declaredDelta->rewrite($derived);
    }

    /** Looking up an intention does not credit it as performed. */
    public function hasIntention(string $key): bool
    {
        return \in_array($this->declaredDelta->intentOf($key), $this->declaredDelta->surfaces(), true);
    }

    /**
     * A declared surface that differs after maps and normalization: recorded
     * while deriving, otherwise held to its explicit intention.
     */
    public function checkDifference(string $key, string $left, string $right): void
    {
        $diff = ExactDiff::between($left, $right, 'candidate', 'reference (mapped)');

        $declared = $this->declaredDelta->claim($key) ?? throw new GateError('A structural delta comparison requires an explicit intention: ' . $key);
        if ($this->derived !== null) {
            $intent = $this->declaredDelta->intentOf($key);
            $measured = $this->render($key, $diff);
            if (isset($this->derived[$intent]) && $this->derived[$intent] !== $measured) {
                $this->report->fail(FailureClass::DELTA_MISMATCH, $key, 'This case measures a different diff for the declared surface class.');
            }
            $this->derived[$intent] = $measured;
        }

        $this->checkAgainstDeclaredDelta($key, $diff, $declared, $left, $right);
        foreach ($this->report->raised() as $failure) {
            if ($failure['scope'] === $key && \in_array($failure['class'], [FailureClass::DELTA_TOO_LARGE, FailureClass::DELTA_OVERREACH, FailureClass::DELTA_MISMATCH], true)) {
                $this->unexpressible[$this->declaredDelta->intentOf($key)] = true;
            }
        }
    }

    /**
     * Holds a differing surface to its declaration, on all four properties.
     *
     * Size and reach are judged on the MEASURED diff, not on the declared text:
     * a declaration that reaches too far must fail for reaching too far, and not
     * be excused by also failing to match.
     */
    private function checkAgainstDeclaredDelta(string $key, ExactDiff $diff, string $declared, string $left, string $right): void
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

        try {
            $overreaching = $this->overreachingLines($key, $left, $right);
        } catch (GateError $error) {
            $overreaching = [$error->getMessage()];
        }
        foreach ($overreaching as $problem) {
            $this->report->fail(
                FailureClass::DELTA_OVERREACH,
                $key,
                $problem . ' A declared delta may change a compared field only inside a record whose (rule, code)'
                . ' pair a declared split already explains, or where a ' . DeclaredFieldMoves::INDEX . ' row names'
                . ' this exact pair of values on this exact surface — the waiver normalization was refused is'
                . ' refused here too.',
            );
        }

        if ($this->derived !== null || $this->render($key, $diff) === $declared) {
            return;
        }

        $this->report->fail(
            FailureClass::DELTA_MISMATCH,
            $key,
            \sprintf(
                'The measured diff is not the declared one (%s). Re-derive it with --derive-declarations and review'
                . ' what moved.',
                $this->declaredDelta->fileOf($key),
            ),
            [
                ...Diff::between($declared, $this->render($key, $diff), 'declared delta', 'measured diff'),
                ...$diff->tokenDetail(),
            ],
        );
    }

    /** @return list<string> */
    private function overreachingLines(string $key, string $left, string $right): array
    {
        $fields = EquivalenceTuple::load($this->options->candidateRoot)->fields;
        $surface = Surfaces::surfaceClass($key);
        $candidate = $this->comparedPublications($surface, $left);
        $reference = $this->comparedPublications($surface, $right);
        $problems = [];
        foreach ($fields as $field) {
            $a = [];
            $b = [];
            foreach ($candidate as $record) {
                if (\array_key_exists($field, $record)) {
                    $a[] = $record[$field];
                }
            }
            foreach ($reference as $record) {
                if (\array_key_exists($field, $record)) {
                    $b[] = $record[$field];
                }
            }
            if ($a === $b) {
                continue;
            }
            if (\count($a) !== \count($b)) {
                $problems[] = 'The complete record publication changes the number of compared ' . $field . ' values.';
                continue;
            }
            foreach ($b as $index => $from) {
                $to = $a[$index];
                if ($from === $to) {
                    continue;
                }
                $old = \is_string($from) ? $from : ValueCheck::value($from);
                $new = \is_string($to) ? $to : ValueCheck::value($to);
                if ($this->split->allowsMove($field, $old, $new) || $this->declaredFieldMoves->allows($key, $field, $old, $new)) {
                    continue;
                }
                $problems[] = 'The complete record publication changes compared ' . $field . ' without an exact licensed value pair.';
            }
        }
        return array_values(array_unique($problems));
    }

    /** @return list<array<string,mixed>> */
    private function comparedPublications(string $surface, string $text): array
    {
        if (\in_array($surface, ['format:json', 'format:suppressed', 'format:sarif', 'format:gitlab'], true)) {
            $document = ReportRecords::decode($text);
            if (\is_string($document['error'] ?? null) && \is_int($document['exit_code'] ?? null)) {
                return [];
            }
        }
        if (\in_array($surface, ['format:json', 'check:baseline-source', 'check:baseline', 'check:output:file', 'check:parallel', 'format:suppressed'], true)) {
            $document = ReportRecords::decode($text);
            $member = $surface === 'format:suppressed' ? 'suppressed' : 'violations';
            if (!\is_array($document[$member] ?? null) || !array_is_list($document[$member])) {
                throw new GateError('A structural intention has no observed complete record list.');
            }
            $records = [];
            foreach ($document[$member] as $record) {
                if (!\is_array($record) || array_is_list($record)) {
                    throw new GateError('A structural intention encounters a malformed compared record.');
                }
                if ($surface === 'format:suppressed' && \array_key_exists('channel', $record)) {
                    $record['code'] = $record['channel'];
                    unset($record['channel']);
                }
                $records[] = $record;
            }
            return $records;
        }
        if (\in_array($surface, ['format:html', 'format:sarif', 'format:gitlab'], true)) {
            $records = [];
            $aliases = match ($surface) {
                'format:html' => ['ruleName' => 'rule', 'violationCode' => 'code', 'symbolPath' => 'symbol'],
                'format:sarif' => ['ruleId' => 'code', 'level' => 'severity'],
                default => ['description' => 'message', 'check_name' => 'code'],
            };
            foreach (ReportRecords::projected($surface, $text) as $entry) {
                $record = $entry['fields'];
                foreach ($aliases as $published => $field) {
                    if (\array_key_exists($published, $record)) {
                        $record[$field] = $record[$published];
                        unset($record[$published]);
                    }
                }
                if ($surface === 'format:sarif') {
                    $record['message'] = $record['message']['text'];
                } elseif ($surface === 'format:gitlab') {
                    $record['file'] = $record['location']['path'];
                    $record['line'] = $record['location']['lines']['begin'];
                }
                $records[] = $record;
            }
            return $records;
        }
        if ($surface === 'format:checkstyle') {
            return ReportRecords::checkstyle($text);
        }
        if (\in_array($surface, ProseRecords::SURFACES, true)) {
            return array_column(ProseRecords::extract($surface, $text), 'fields');
        }
        if ($surface === 'baseline-file') {
            $document = ReportRecords::decode($text);
            $records = [];
            if (!\is_array($document['entries'] ?? null)) {
                throw new GateError('A structural baseline intention has no observed entry population.');
            }
            foreach ($document['entries'] as $subject => $entries) {
                foreach ($entries as $entry) {
                    $records[] = ['subject' => $subject, ...$entry];
                }
            }
            return $records;
        }
        return [];
    }

    private function render(string $key, ExactDiff $diff): string
    {
        $rendered = $diff->render();
        return str_contains($this->declaredDelta->intentOf($key), '|')
            ? $rendered
            : (preg_replace('~^@@[^\n]*\n~m', '', $rendered) ?? throw new GateError('Cannot canonicalize a structural diff.'));
    }

    public function observeEqual(string $key): void
    {
        $intent = $this->declaredDelta->intentOf($key);
        if (!str_contains($intent, '|') && \in_array($intent, $this->declaredDelta->surfaces(), true)) {
            $this->report->fail(FailureClass::DELTA_STALE, $key, 'A surface-class intention must move this case too.');
        }
    }

    /**
     * A surface a declared delta covers that turned out to be equal.
     *
     * The same lie as a stale map row: a declaration of a change nobody can
     * point at.
     */
    public function checkStaleDeclaredDelta(): void
    {
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
