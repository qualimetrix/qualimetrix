<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The published finding's shape against the tracked equivalence tuple: the tuple still derives from the
 * publishing code, the fingerprint is composed only of fields it compares, and every published finding
 * carries exactly its keys.
 */
final class TupleCheck
{
    public function __construct(
        private readonly Options $options,
        private readonly GateReport $report,
    ) {}

    public function checkTuple(): void
    {
        $tracked = EquivalenceTuple::load($this->options->candidateRoot);
        $derived = EquivalenceTuple::derive($this->options->candidateRoot);

        if (!$tracked->equals($derived)) {
            $this->report->fail(
                FailureClass::TUPLE_FIELD_DRIFT,
                EquivalenceTuple::TRACKED_PATH,
                'The published finding fields no longer match the tracked tuple. Re-derive it with --derive-tuple and'
                . ' review what a step added to or removed from the published surface.',
                Diff::betweenSets($tracked->fields, $derived->fields, 'tracked tuple', 'publishing code'),
            );
        }

        $this->report->fact('tuple fields', \count($derived->fields));

        // The licence for substituting the opaque fingerprint, asserted where
        // the tuple is loaded rather than argued in a docblock. An input the
        // tuple does not compare would be a datum only the hash carries, and
        // replacing the hash would retire it from the comparison — the same hole
        // `normalization-overreach` exists for.
        $outside = array_values(array_diff(Fingerprints::INPUT_FIELDS, $derived->fields));

        if ($outside !== []) {
            $this->report->fail(
                FailureClass::TUPLE_FIELD_DRIFT,
                EquivalenceTuple::TRACKED_PATH,
                \sprintf(
                    'The fingerprint is composed from published field(s) the tuple does not compare: %s. Until the'
                    . ' tuple covers them, the GitLab hash cannot be replaced by the identity it states.',
                    implode(', ', $outside),
                ),
            );
        }
    }

    /** @param list<array<string, mixed>> $findings */
    public function checkTupleAgainstFindings(string $side, CaseDefinition $case, EquivalenceTuple $tuple, array $findings): void
    {
        foreach ($findings as $index => $finding) {
            $keys = array_keys($finding);

            if ($keys === $tuple->fields) {
                continue;
            }

            $this->report->fail(
                FailureClass::FINDING_TUPLE_MISMATCH,
                \sprintf('%s / %s / finding #%d', $side, $case->id, $index),
                'A published finding object\'s key set is not the tracked tuple. A field that exists but is not'
                . ' compared must be impossible, so this is a failure rather than a wider comparison.',
                Diff::betweenSets($tuple->fields, array_map(strval(...), $keys), 'tuple', 'finding'),
            );

            return;
        }
    }
}
