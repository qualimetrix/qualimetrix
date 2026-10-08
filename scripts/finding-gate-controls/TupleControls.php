<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\CaseOutcome;
use QmxFindingGate\Corpus;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredFields;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\EquivalenceTuple;
use QmxFindingGate\FailureClass;
use QmxFindingGate\ReportViews;
use QmxFindingGate\Surfaces;
use QmxFindingGate\Tsv;
use RuntimeException;

/** Unannounced published members cannot disappear behind the tracked tuple. */
final class TupleControls
{
    public static function publisherDrift(): Control
    {
        return Control::red(
            'tuple-publisher-drift',
            'the JSON publisher adds a member absent from the tracked equivalence tuple',
            self::addedMember()->and(self::unavailableFindingValueMeasurements())->and(self::unavailableRankingFieldMeasurements()),
            [new Expectation(FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH),
                new Expectation(FailureClass::RUN_FAILED, 'candidate-2 / annotations', exactScope: true),
                new Expectation(FailureClass::RUN_FAILED, 'candidate-claims / utf8-identifier', exactScope: true),
                new Expectation(FailureClass::CANDIDATE_INPUT_REFUSED, 'case:utf8-identifier', exactScope: true),
                ...self::recordExpectations('candidate')],
            [...self::invalidCaptureBaselineFailures(),
                ...self::rankingProjectionFailures('reference'),
                new Expectation(FailureClass::FINDING_COUNT_MISMATCH, 'case:drill-down', exactScope: true),
                ...self::staleRecordFailures()],
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
            ))->and(self::unavailableFindingValueMeasurements())->and(self::unavailableRankingFieldMeasurements()),
            self::recordExpectations('reference'),
            [...self::rankingProjectionFailures('candidate'),
                new Expectation(FailureClass::RANKING_PROJECTION_MISMATCH, 'reference / case:drill-down|check:baseline', exactScope: true),
                new Expectation(FailureClass::FINDING_COUNT_MISMATCH, 'case:drill-down', exactScope: true),
                ...self::staleRecordFailures()],
        );
    }

    private static function addedMember(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/Formatter/FindingRecord.php',
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

    /** The broken publication cannot supply the declared ranking field measurements. */
    private static function unavailableRankingFieldMeasurements(): Mutation
    {
        $root = \dirname(__DIR__, 2) . '/finding-gate';
        $fields = DeclaredFields::load($root);
        $changes = $fields->changes('json', 'ranking');
        $intents = Tsv::rows($root . '/' . DeclaredFields::INDEX, DeclaredFields::COLUMNS);
        $derived = Tsv::rows($root . '/' . DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS);
        $retainedIntents = array_values(array_filter($intents, static fn(array $row): bool =>
            $row['report'] !== 'json' || $row['view'] !== 'ranking' || !isset($changes[$row['field']])));
        $retainedDerived = array_values(array_filter($derived, static fn(array $row): bool =>
            $row['report'] !== 'json' || $row['view'] !== 'ranking'));

        if ($retainedIntents === $intents && $retainedDerived === $derived) {
            return Mutation::none();
        }

        $mutation = $retainedIntents === $intents ? Mutation::none()
            : Mutation::replace(
                ['finding-gate/' . DeclaredFields::INDEX => Tsv::render(
                    DeclaredFields::COLUMNS,
                    array_map(static fn(array $row): array => array_values($row), $retainedIntents),
                )],
                'the broken publication cannot measure ranking field intentions in this private tree',
            );

        return $retainedDerived === $derived ? $mutation
            : $mutation->and(Mutation::replace(
                ['finding-gate/' . DeclaredFields::DERIVED => Tsv::render(
                    DeclaredFields::DERIVED_COLUMNS,
                    array_map(static fn(array $row): array => array_values($row), $retainedDerived),
                )],
                'the unavailable ranking field measurements leave the private tree',
            ));
    }

    /** @return list<Expectation> */
    private static function rankingProjectionFailures(string $side): array
    {
        $scopes = array_map(static fn(string $case): string => 'case:' . $case . '|format:json', [
            'annotations',
            'applied-threshold',
            'baseline-cycle',
            'complexity',
            'config-precedence',
            'coupling',
            'cycle',
            'design',
            'detectors',
            'detectors-security',
            'detectors-smells',
            'directive-placement',
            'disabled-rule',
            'disabled-rule-duplication',
            'discovery',
            'drill-down',
            'duplicate-declaration',
            'duplication',
            'duplication-size',
            'excluded-path',
            'external-parent',
            'health',
            'incomplete-directory-symlink',
            'layered-threshold',
            'layers',
            'name-case',
            'only-rules',
            'parallel-files',
            'rule-exclusion-ledger',
            'scoped-layers',
            'security',
            'smells',
            'stderr-warning',
            'suppression',
            'threshold-raising',
        ]);
        foreach (['baseline-cycle', 'drill-down', 'scoped-layers'] as $case) {
            $scopes[] = 'case:' . $case . '|check:baseline-source';
            $scopes[] = 'case:' . $case . '|check:baseline';
        }
        return array_map(static fn(string $scope): Expectation => new Expectation(
            FailureClass::RANKING_PROJECTION_MISMATCH,
            $side . ' / ' . $scope,
            exactScope: true,
        ), $scopes);
    }

    /** @return list<Expectation> */
    private static function staleRecordFailures(): array
    {
        return [
            new Expectation(FailureClass::RECORD_STALE, 'case:drill-down|format:json', exactScope: true),
            new Expectation(FailureClass::RECORD_STALE, 'case:drill-down|check:baseline', exactScope: true),
            new Expectation(FailureClass::RECORD_STALE, 'declared-records.tsv', exactScope: true),
        ];
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
            if ($case->channels === [] || $case->id === 'utf8-identifier'
                || !CaseOutcome::applies(CaseOutcome::CHECK_TUPLE, CaseOutcome::of($case, $side))) {
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
