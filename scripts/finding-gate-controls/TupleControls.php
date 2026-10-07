<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\CaseOutcome;
use QmxFindingGate\Corpus;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\EquivalenceTuple;
use QmxFindingGate\FailureClass;
use QmxFindingGate\ReportViews;
use QmxFindingGate\Surfaces;
use RuntimeException;

/** Unannounced published members cannot disappear behind the tracked tuple. */
final class TupleControls
{
    public static function publisherDrift(): Control
    {
        return Control::red(
            'tuple-publisher-drift',
            'the JSON publisher adds a member absent from the tracked equivalence tuple',
            self::addedMember()->and(self::unavailableFindingValueMeasurements()),
            [new Expectation(FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH),
                new Expectation(FailureClass::RUN_FAILED, 'candidate-2 / annotations', exactScope: true),
                ...self::recordExpectations('candidate')],
            self::invalidCaptureBaselineFailures(),
        );
    }

    public static function addedMemberWithoutIntent(): Control
    {
        return Control::red(
            'tuple-added-member-without-intent',
            'the candidate tuple includes a new member but no field intent states the reference shape',
            self::addedMember()->and(Mutation::append(
                EquivalenceTuple::TRACKED_PATH,
                'probe' . "\t" . EquivalenceTuple::source() . "\n",
                'the tracked candidate tuple includes the unannounced member',
            ))->and(self::unavailableFindingValueMeasurements()),
            self::recordExpectations('reference'),
        );
    }

    private static function addedMember(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/Formatter/Json/JsonFindingSection.php',
            ["            'baselineReason' => \$baseline['baselineReason'],\n        ];" => "            'baselineReason' => \$baseline['baselineReason'],\n            'probe' => 1,\n        ];"],
            'the published finding receives an extra observed member',
        );
    }

    /**
     * The added finding member invalidates JSON authorities before their field
     * values can be paired. Suppressed records use a separate publication.
     */
    private static function unavailableFindingValueMeasurements(): Mutation
    {
        $values = DeclaredValues::load(\dirname(__DIR__, 2) . '/finding-gate');
        $unavailable = [];
        $retained = [];
        $derivedRows = [];
        $jsonViews = ReportViews::views('json');

        foreach ($values->derived() as $row) {
            if ($row['kind'] !== DeclaredValues::FIELD) {
                continue;
            }

            if (!str_contains($row['subject'], '|record:')) {
                $retained[$row['key']] = true;
                continue;
            }
            [$surface] = explode('|record:', $row['subject'], 2);
            if (\in_array(Surfaces::surfaceClass($surface), $jsonViews, true)) {
                $unavailable[$row['key']] = true;
                $derivedRows[implode("\t", array_values($row)) . "\n"] = '';
            } else {
                $retained[$row['key']] = true;
            }
        }

        $intentRows = [];
        foreach ($values->intents() as $row) {
            if ($row['kind'] === DeclaredValues::FIELD
                && isset($unavailable[$row['key']])
                && !isset($retained[$row['key']])) {
                $intentRows[implode("\t", array_values($row)) . "\n"] = '';
            }
        }

        $mutation = $intentRows === []
            ? Mutation::none()
            : Mutation::edit(
                'finding-gate/' . DeclaredValues::INDEX,
                $intentRows,
                'the invalid JSON authority cannot measure this field value intent',
            );

        return $derivedRows === []
            ? $mutation
            : $mutation->and(Mutation::edit(
                'finding-gate/' . DeclaredValues::DERIVED,
                $derivedRows,
                'the unavailable JSON field measurements leave the scratch table',
            ));
    }

    /** @return list<Expectation> */
    private static function invalidCaptureBaselineFailures(): array
    {
        $root = \dirname(__DIR__, 2);
        $exact = array_fill_keys(Declarations::load($root)->exactSurfaces->keys(), true);
        $failures = [];

        foreach (Corpus::load($root)->cases as $case) {
            if (CaseOutcome::of($case, 'reference') !== CaseOutcome::REFUSAL
                || !CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, 'candidate'))) {
                continue;
            }

            $scope = 'case:' . $case->id . '|baseline-file';
            if (isset($exact[$scope])) {
                $failures[] = new Expectation(FailureClass::SURFACE_MISMATCH, $scope, exactScope: true);
            }
        }

        return $failures;
    }

    /** @return list<Expectation> */
    private static function recordExpectations(string $side): array
    {
        $required = [];
        foreach (Corpus::load(\dirname(__DIR__, 2))->cases as $case) {
            if ($case->channels === [] || !CaseOutcome::applies(CaseOutcome::CHECK_TUPLE, CaseOutcome::of($case, $side))) {
                continue;
            }
            $required[] = new Expectation(FailureClass::FINDING_TUPLE_MISMATCH, $side . ' / ' . $case->id . ' / finding');
            $required[] = new Expectation(FailureClass::RECORD_PROJECTION_MISMATCH, $side . ' / case:' . $case->id . '|');
        }
        if ($required === []) {
            throw new RuntimeException('The tuple member control has no populated publication to judge.');
        }
        return $required;
    }
}
