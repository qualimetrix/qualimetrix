<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\CaseOutcome;
use QmxFindingGate\Corpus;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\EquivalenceTuple;
use QmxFindingGate\FailureClass;
use RuntimeException;

/** Unannounced published members cannot disappear behind the tracked tuple. */
final class TupleControls
{
    public static function publisherDrift(): Control
    {
        return Control::red(
            'tuple-publisher-drift',
            'the JSON publisher adds a member absent from the tracked equivalence tuple',
            self::addedMember()->and(self::unavailableNamespaceValueDeclaration()),
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
            ))->and(self::unavailableNamespaceValueDeclaration()),
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
     * Invalid finding records cannot witness the namespace transition. Remove
     * only that intent and its measurements from this control's scratch tree.
     */
    private static function unavailableNamespaceValueDeclaration(): Mutation
    {
        $values = DeclaredValues::load(\dirname(__DIR__, 2) . '/finding-gate');
        $intentRows = [];
        $derivedRows = [];

        foreach ($values->intents() as $row) {
            if ($row['kind'] === DeclaredValues::FIELD && $row['key'] === 'namespace') {
                $intentRows[implode("\t", array_values($row)) . "\n"] = '';
            }
        }
        foreach ($values->derived() as $row) {
            if ($row['kind'] === DeclaredValues::FIELD && $row['key'] === 'namespace') {
                $derivedRows[implode("\t", array_values($row)) . "\n"] = '';
            }
        }

        $mutation = $intentRows === []
            ? Mutation::none()
            : Mutation::edit(
                'finding-gate/' . DeclaredValues::INDEX,
                $intentRows,
                'the invalid records cannot measure the namespace value intent',
            );

        return $derivedRows === []
            ? $mutation
            : $mutation->and(Mutation::edit(
                'finding-gate/' . DeclaredValues::DERIVED,
                $derivedRows,
                'the unavailable namespace measurements leave the scratch table',
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
