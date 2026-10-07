<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\{Declarations, DeclaredValues, FailureClass, Normalization};

final class CorpusCaseControls
{
    public static function configPrecedence(): Control
    {
        return self::product(
            'config-precedence',
            'src/Analysis/Evidence/Complexity/ComplexityOptions.php',
            "])->withLevelSlots(self::levelOptionsClasses())->spreadingInto('threshold', ['callable.threshold']);",
            '])->withLevelSlots(self::levelOptionsClasses());',
            [
                FailureClass::CASE_CLAIM_MISMATCH => [
                    'case:config-precedence',
                ],
                FailureClass::FINDING_COUNT_MISMATCH => [
                    'case:config-precedence',
                ],
                FailureClass::RECORD_UNDECLARED => [
                    'case:config-precedence|format:json',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:config-precedence|baseline-file',
                    'case:config-precedence|check:output:file',
                    ...self::uncoveredDirectives('config-precedence'),
                    'case:config-precedence|format:checkstyle',
                    'case:config-precedence|format:github',
                    'case:config-precedence|format:gitlab',
                    'case:config-precedence|format:html',
                    'case:config-precedence|format:json',
                    'case:config-precedence|format:metrics',
                    'case:config-precedence|format:sarif',
                    'case:config-precedence|format:summary',
                    'case:config-precedence|format:text',
                    'case:config-precedence|format:text-detail',
                    'case:config-precedence|show-suppressed',
                ],
            ],
        );
    }

    public static function thresholdRaising(): Control
    {
        return self::product(
            'threshold-raising',
            'src/Analysis/Evidence/Design/GodClass/GodClassOptions.php',
            'minCriteria: $warning !== null ? (int) $warning : $this->minCriteria,',
            'minCriteria: $warning !== null ? min((int) $warning, $this->minCriteria) : $this->minCriteria,',
            [
                FailureClass::FINDING_COUNT_MISMATCH => [
                    'case:threshold-raising',
                ],
                FailureClass::RECORD_UNDECLARED => [
                    'case:threshold-raising|format:json',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:threshold-raising|baseline-file',
                    'case:threshold-raising|check:output:file',
                    ...self::uncoveredDirectives('threshold-raising'),
                    'case:threshold-raising|format:checkstyle',
                    'case:threshold-raising|format:github',
                    'case:threshold-raising|format:gitlab',
                    'case:threshold-raising|format:html',
                    'case:threshold-raising|format:json',
                    'case:threshold-raising|format:metrics',
                    'case:threshold-raising|format:sarif',
                    'case:threshold-raising|format:summary',
                    'case:threshold-raising|format:text',
                    'case:threshold-raising|format:text-detail',
                    'case:threshold-raising|show-suppressed',
                ],
                FailureClass::VALUE_MISMATCH => [
                    'case:threshold-raising|directives|record:{"file":"src/Design.php","line":5,"form":"threshold","target":"design.god-class"}',
                ],
            ],
        );
    }

    public static function directivePlacement(): Control
    {
        return self::product(
            'directive-placement',
            'src/Analysis/Policy/Inline/Extraction/UnattachedComments.php',
            'return $owner;',
            'return null;',
            [
                FailureClass::FINDING_COUNT_MISMATCH => [
                    'case:directive-placement',
                ],
                FailureClass::RECORD_UNDECLARED => [
                    'case:directive-placement|format:json',
                    'case:directive-placement|format:suppressed',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:directive-placement|baseline-file',
                    'case:directive-placement|check:output:file',
                    ...self::uncoveredDirectives('directive-placement'),
                    'case:directive-placement|format:checkstyle',
                    'case:directive-placement|format:github',
                    'case:directive-placement|format:gitlab',
                    'case:directive-placement|format:html',
                    'case:directive-placement|format:json',
                    'case:directive-placement|format:metrics',
                    'case:directive-placement|format:sarif',
                    'case:directive-placement|format:summary',
                    'case:directive-placement|format:suppressed',
                    'case:directive-placement|format:text',
                    'case:directive-placement|format:text-detail',
                    'case:directive-placement|show-suppressed',
                    'case:directive-placement|stderr:show-suppressed',
                ],
                FailureClass::VALUE_MISMATCH => [
                    'case:directive-placement|directives|record:{"file":"src/Placed.php","line":19,"form":"symbol","target":"complexity.ccn"}',
                ],
            ],
            scratchDeclarations: self::withdrawnDirectiveMessageDeclaration(),
        );
    }

    public static function warningClockRow(): Control
    {
        $row = 'stderr' . "\t" . Normalization::WARNING_TIME_PATTERN . "\tline-regex\t" . Normalization::MEASURED_REASON . "\n";
        return Control::red(
            'corpus-warning-clock-row-missing',
            'the warning clock remains a compared field when its public normalization row is removed',
            Mutation::edit('finding-gate/normalization.tsv', [$row => ''], 'remove only the generic warning-clock exclusion'),
            self::expectations([
                FailureClass::NONDETERMINISM_UNDECLARED => [
                    'case:stderr-warning|stderr:baseline-file',
                    'case:stderr-warning|stderr:directives',
                    'case:stderr-warning|stderr:format:checkstyle',
                    'case:stderr-warning|stderr:format:github',
                    'case:stderr-warning|stderr:format:gitlab',
                    'case:stderr-warning|stderr:format:health',
                    'case:stderr-warning|stderr:format:html',
                    'case:stderr-warning|stderr:format:json',
                    'case:stderr-warning|stderr:format:metrics',
                    'case:stderr-warning|stderr:format:sarif',
                    'case:stderr-warning|stderr:format:summary',
                    'case:stderr-warning|stderr:format:suppressed',
                    'case:stderr-warning|stderr:format:text',
                    'case:stderr-warning|stderr:format:text-detail',
                    'case:stderr-warning|stderr:show-suppressed',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:stderr-warning|stderr:baseline-file',
                    'case:stderr-warning|stderr:directives',
                    'case:stderr-warning|stderr:format:checkstyle',
                    'case:stderr-warning|stderr:format:github',
                    'case:stderr-warning|stderr:format:gitlab',
                    'case:stderr-warning|stderr:format:health',
                    'case:stderr-warning|stderr:format:html',
                    'case:stderr-warning|stderr:format:json',
                    'case:stderr-warning|stderr:format:metrics',
                    'case:stderr-warning|stderr:format:sarif',
                    'case:stderr-warning|stderr:format:summary',
                    'case:stderr-warning|stderr:format:suppressed',
                    'case:stderr-warning|stderr:format:text',
                    'case:stderr-warning|stderr:format:text-detail',
                    'case:stderr-warning|stderr:show-suppressed',
                ],
            ]),
        );
    }

    public static function parallelFiles(): Control
    {
        return self::product(
            'parallel-files',
            'src/Infrastructure/Parallel/Strategy/AmphpParallelStrategy.php',
            'array_push($results, ...$batchResults);',
            'array_push($results, ...array_reverse($batchResults));',
            [
                FailureClass::SURFACE_MISMATCH => [
                    'case:parallel-files|check:parallel',
                ],
            ],
        );
    }

    /** @return list<string> */
    private static function uncoveredDirectives(string $case): array
    {
        $scope = 'case:' . $case . '|directives';
        $declarations = Declarations::load(\dirname(__DIR__, 2));

        return $declarations->delta->hasSurfaceIntention($scope)
            || \in_array($scope, $declarations->exactSurfaces->keys(), true)
            ? []
            : [$scope];
    }

    private static function withdrawnDirectiveMessageDeclaration(): Mutation
    {
        $rows = [];
        foreach (DeclaredValues::load(\dirname(__DIR__, 2) . '/finding-gate')->derived() as $row) {
            if ($row['kind'] !== DeclaredValues::FIELD || $row['key'] !== 'message'
                || !str_starts_with($row['subject'], 'case:directive-placement|format:suppressed|record:')) {
                continue;
            }

            [, $identity] = explode('|record:', $row['subject'], 2);
            $record = json_decode($identity, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($record)
                || ($record['suppressor'] ?? null) !== 'src/Placed.php:19'
                || ($record['subject'] ?? null) !== 'declaration:callable:Corpus\\DirectivePlacement\\Placed::afterAttribute@src/Placed.php') {
                continue;
            }

            $rows[implode("\t", array_values($row)) . "\n"] = '';
        }

        return $rows === []
            ? Mutation::none()
            : Mutation::edit(
                'finding-gate/' . DeclaredValues::DERIVED,
                $rows,
                'the withdrawn suppressed message has no measurement in this scratch run',
            );
    }

    /**
     * @param array<string,list<string>> $failures
     *
     * @return list<Expectation>
     */
    private static function expectations(array $failures): array
    {
        $expectations = [];
        foreach ($failures as $failure => $scopes) {
            foreach ($scopes as $scope) {
                $expectations[] = new Expectation($failure, $scope, exactScope: true);
            }
        }
        return $expectations;
    }

    /** @param array<string,list<string>> $failures */
    private static function product(string $case, string $path, string $old, string $replacement, array $failures, ?string $id = null, ?Mutation $scratchDeclarations = null): Control
    {
        $mutation = Mutation::edit($path, [$old => $replacement], 'perturb the product behaviour exercised by ' . $case);

        return Control::red(
            $id ?? 'corpus-' . $case,
            'the product changes only the ' . $case . ' case',
            $scratchDeclarations === null ? $mutation : $mutation->and($scratchDeclarations),
            self::expectations($failures),
        );
    }
}
