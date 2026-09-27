<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\{FailureClass, Normalization};

final class CorpusCaseControls
{
    public static function incompleteDirectorySymlink(): Control
    {
        return self::product(
            'incomplete-directory-symlink',
            'src/Analysis/Run/Discovery/FinderFileDiscovery.php',
            "            \$this->record(\n                \$path,\n                AnalysisFailureKind::DirectorySymlink,\n                'Symbolic link to a directory is not traversed',\n            );",
            '',
            [
                FailureClass::CASE_OUTCOME_MISMATCH => [
                    'candidate / incomplete-directory-symlink',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:incomplete-directory-symlink|baseline-file',
                    'case:incomplete-directory-symlink|check:output:file',
                    'case:incomplete-directory-symlink|directives',
                    'case:incomplete-directory-symlink|exit:baseline:generate',
                    'case:incomplete-directory-symlink|exit:check:output',
                    'case:incomplete-directory-symlink|exit:directives',
                    'case:incomplete-directory-symlink|exit:format:checkstyle',
                    'case:incomplete-directory-symlink|exit:format:github',
                    'case:incomplete-directory-symlink|exit:format:gitlab',
                    'case:incomplete-directory-symlink|exit:format:health',
                    'case:incomplete-directory-symlink|exit:format:html',
                    'case:incomplete-directory-symlink|exit:format:json',
                    'case:incomplete-directory-symlink|exit:format:metrics',
                    'case:incomplete-directory-symlink|exit:format:sarif',
                    'case:incomplete-directory-symlink|exit:format:summary',
                    'case:incomplete-directory-symlink|exit:format:suppressed',
                    'case:incomplete-directory-symlink|exit:format:text',
                    'case:incomplete-directory-symlink|exit:format:text-verbose',
                    'case:incomplete-directory-symlink|exit:graph:export',
                    'case:incomplete-directory-symlink|exit:show-suppressed',
                    'case:incomplete-directory-symlink|format:checkstyle',
                    'case:incomplete-directory-symlink|format:github',
                    'case:incomplete-directory-symlink|format:gitlab',
                    'case:incomplete-directory-symlink|format:health',
                    'case:incomplete-directory-symlink|format:html',
                    'case:incomplete-directory-symlink|format:json',
                    'case:incomplete-directory-symlink|format:metrics',
                    'case:incomplete-directory-symlink|format:sarif',
                    'case:incomplete-directory-symlink|format:summary',
                    'case:incomplete-directory-symlink|format:suppressed',
                    'case:incomplete-directory-symlink|format:text',
                    'case:incomplete-directory-symlink|format:text-verbose',
                    'case:incomplete-directory-symlink|graph:export',
                    'case:incomplete-directory-symlink|show-suppressed',
                    'case:incomplete-directory-symlink|stderr:graph:export',
                ],
                FailureClass::VALUE_MISMATCH => [
                    'case:incomplete-directory-symlink|baseline-file',
                    'case:incomplete-directory-symlink|check:output',
                    'case:incomplete-directory-symlink|directives',
                    'case:incomplete-directory-symlink|format:checkstyle',
                    'case:incomplete-directory-symlink|format:github',
                    'case:incomplete-directory-symlink|format:gitlab',
                    'case:incomplete-directory-symlink|format:health',
                    'case:incomplete-directory-symlink|format:html',
                    'case:incomplete-directory-symlink|format:json',
                    'case:incomplete-directory-symlink|format:metrics',
                    'case:incomplete-directory-symlink|format:sarif',
                    'case:incomplete-directory-symlink|format:summary',
                    'case:incomplete-directory-symlink|format:suppressed',
                    'case:incomplete-directory-symlink|format:text',
                    'case:incomplete-directory-symlink|format:text-verbose',
                    'case:incomplete-directory-symlink|graph:export',
                    'case:incomplete-directory-symlink|show-suppressed',
                ],
            ],
        );
    }

    public static function scopedLayers(): Control
    {
        return self::product(
            'scoped-layers',
            'src/Analysis/Policy/Architecture/LayerViolation/LayerDeclarationValidator.php',
            '$judgesAbsence = $context->coversProjectScope;',
            '$judgesAbsence = true;',
            [
                FailureClass::CASE_CLAIM_MISMATCH => [
                    'case:scoped-layers',
                ],
                FailureClass::FINDING_COUNT_MISMATCH => [
                    'case:scoped-layers',
                ],
                FailureClass::RECORD_UNDECLARED => [
                    'case:scoped-layers|check:baseline',
                    'case:scoped-layers|format:json',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:scoped-layers|check:baseline',
                    'case:scoped-layers|check:output:file',
                    'case:scoped-layers|directives',
                    'case:scoped-layers|format:checkstyle',
                    'case:scoped-layers|format:github',
                    'case:scoped-layers|format:gitlab',
                    'case:scoped-layers|format:html',
                    'case:scoped-layers|format:json',
                    'case:scoped-layers|format:metrics',
                    'case:scoped-layers|format:sarif',
                    'case:scoped-layers|format:summary',
                    'case:scoped-layers|format:text',
                    'case:scoped-layers|format:text-verbose',
                    'case:scoped-layers|show-suppressed',
                ],
            ],
        );
    }

    public static function duplicationSize(): Control
    {
        return self::product(
            'duplication-size',
            'src/Analysis/Evidence/Duplication/DuplicateBlockFinder.php',
            '$longest = max(array_map(function (int $copy) use ($length): int {',
            '$longest = min(array_map(function (int $copy) use ($length): int {',
            [
                FailureClass::CASE_CLAIM_MISMATCH => [
                    'case:duplication-size',
                ],
                FailureClass::FINDING_COUNT_MISMATCH => [
                    'case:duplication-size',
                ],
                FailureClass::RECORD_UNDECLARED => [
                    'case:duplication-size|format:json',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'candidate / case:duplication-size|format:github',
                    'case:duplication-size|baseline-file',
                    'case:duplication-size|check:output:file',
                    'case:duplication-size|directives',
                    'case:duplication-size|format:checkstyle',
                    'case:duplication-size|format:github',
                    'case:duplication-size|format:gitlab',
                    'case:duplication-size|format:html',
                    'case:duplication-size|format:json',
                    'case:duplication-size|format:metrics',
                    'case:duplication-size|format:sarif',
                    'case:duplication-size|format:summary',
                    'case:duplication-size|format:text',
                    'case:duplication-size|format:text-verbose',
                    'case:duplication-size|show-suppressed',
                ],
            ],
        );
    }

    public static function unknownScope(): Control
    {
        return self::product(
            'scoped-layers',
            'src/Analysis/Run/Configuration/ProjectScopeState.php',
            'return $this !== self::Narrowed;',
            'return $this === self::Covered;',
            [
                FailureClass::RECORD_UNDECLARED => [
                    'case:scoped-layers|check:baseline-source',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:scoped-layers|check:baseline-source',
                ],
            ],
            'corpus-unknown-scope',
        );
    }

    public static function configPrecedence(): Control
    {
        return self::product(
            'config-precedence',
            'src/Analysis/Evidence/Complexity/ComplexityOptions.php',
            "if (isset(\$config['threshold'])) {",
            "if (isset(\$config['threshold']) && !isset(\$config['callable'])) {",
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
                    'case:config-precedence|directives',
                    'case:config-precedence|format:checkstyle',
                    'case:config-precedence|format:github',
                    'case:config-precedence|format:gitlab',
                    'case:config-precedence|format:html',
                    'case:config-precedence|format:json',
                    'case:config-precedence|format:metrics',
                    'case:config-precedence|format:sarif',
                    'case:config-precedence|format:summary',
                    'case:config-precedence|format:text',
                    'case:config-precedence|format:text-verbose',
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
                    'case:threshold-raising|directives',
                    'case:threshold-raising|format:checkstyle',
                    'case:threshold-raising|format:github',
                    'case:threshold-raising|format:gitlab',
                    'case:threshold-raising|format:html',
                    'case:threshold-raising|format:json',
                    'case:threshold-raising|format:metrics',
                    'case:threshold-raising|format:sarif',
                    'case:threshold-raising|format:summary',
                    'case:threshold-raising|format:text',
                    'case:threshold-raising|format:text-verbose',
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
                    'case:directive-placement|directives',
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
                    'case:directive-placement|format:text-verbose',
                    'case:directive-placement|show-suppressed',
                    'case:directive-placement|stderr:show-suppressed',
                ],
                FailureClass::VALUE_MISMATCH => [
                    'case:directive-placement|directives|record:{"file":"src/Placed.php","line":19,"form":"symbol","target":"complexity.ccn"}',
                ],
            ],
        );
    }

    public static function computedCrossLevel(): Control
    {
        return self::product(
            'computed-cross-level',
            'src/Analysis/Evidence/ComputedMetrics/ComputedMetricFormulaValidator.php',
            '$byName[$key]->hasLevel($level)',
            '$byName[$key]->hasLevel($byName[$key]->levels[0])',
            [
                FailureClass::SURFACE_MISMATCH => [
                    'case:computed-cross-level|check:output',
                    'case:computed-cross-level|directives',
                    'case:computed-cross-level|format:gitlab',
                    'case:computed-cross-level|format:json',
                    'case:computed-cross-level|format:metrics',
                    'case:computed-cross-level|format:sarif',
                    'case:computed-cross-level|format:suppressed',
                    'case:computed-cross-level|stderr:baseline-file',
                    'case:computed-cross-level|stderr:format:checkstyle',
                    'case:computed-cross-level|stderr:format:github',
                    'case:computed-cross-level|stderr:format:health',
                    'case:computed-cross-level|stderr:format:html',
                    'case:computed-cross-level|stderr:format:summary',
                    'case:computed-cross-level|stderr:format:text',
                    'case:computed-cross-level|stderr:format:text-verbose',
                    'case:computed-cross-level|stderr:show-suppressed',
                ],
            ],
        );
    }

    public static function stderrWarning(): Control
    {
        return self::product(
            'stderr-warning',
            'src/Analysis/Evidence/Design/Inheritance/DitGlobalCollector.php',
            '$this->logger->warning(\sprintf(',
            '$this->logger->debug(\sprintf(',
            [
                FailureClass::SURFACE_MISMATCH => [
                    'case:stderr-warning|stderr:baseline-file',
                    'case:stderr-warning|stderr:check:output',
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
                    'case:stderr-warning|stderr:format:text-verbose',
                    'case:stderr-warning|stderr:show-suppressed',
                ],
            ],
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
                    'case:stderr-warning|stderr:format:text-verbose',
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
                    'case:stderr-warning|stderr:format:text-verbose',
                    'case:stderr-warning|stderr:show-suppressed',
                ],
            ]),
        );
    }

    public static function baselineCycle(): Control
    {
        return self::product(
            'baseline-cycle',
            'src/Analysis/Policy/Baseline/Filter/BaselineCeilingStage.php',
            '? $finding->reportedAsBreach($verdict->breachedLevel)',
            '? $finding',
            [
                FailureClass::SURFACE_MISMATCH => [
                    'case:baseline-cycle|check:baseline',
                    'case:baseline-cycle|exit:check:baseline',
                ],
                FailureClass::VALUE_MISMATCH => [
                    'case:baseline-cycle|check:baseline',
                    'case:baseline-cycle|check:baseline|record:{"channel":"complexity.ccn","subject":"declaration:callable:Corpus\\\\BaselineCycle\\\\Breach::run@src/Breach.php","occurrence":null,"edge":null}',
                ],
            ],
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

    public static function selectorAfterSplit(): Control
    {
        return self::product(
            'selector-after-split',
            'src/Infrastructure/Console/RuleInputValidator.php',
            "\$selector === '' || !\$this->ruleSelector->matchesKnownIn(\$selector, \$producers, \$channels)",
            "\$selector === ''",
            [
                FailureClass::CASE_OUTCOME_MISMATCH => [
                    'candidate / selector-after-split',
                ],
                FailureClass::REFERENCE_INPUT_UNTRANSLATED => [
                    'reference / case:selector-after-split',
                ],
                FailureClass::SURFACE_MISMATCH => [
                    'case:selector-after-split|baseline-file',
                    'case:selector-after-split|check:output',
                    'case:selector-after-split|check:output:file',
                    'case:selector-after-split|directives',
                    'case:selector-after-split|exit:baseline:generate',
                    'case:selector-after-split|exit:check:output',
                    'case:selector-after-split|exit:directives',
                    'case:selector-after-split|exit:format:checkstyle',
                    'case:selector-after-split|exit:format:github',
                    'case:selector-after-split|exit:format:gitlab',
                    'case:selector-after-split|exit:format:health',
                    'case:selector-after-split|exit:format:html',
                    'case:selector-after-split|exit:format:json',
                    'case:selector-after-split|exit:format:metrics',
                    'case:selector-after-split|exit:format:sarif',
                    'case:selector-after-split|exit:format:summary',
                    'case:selector-after-split|exit:format:suppressed',
                    'case:selector-after-split|exit:format:text',
                    'case:selector-after-split|exit:format:text-verbose',
                    'case:selector-after-split|exit:show-suppressed',
                    'case:selector-after-split|format:checkstyle',
                    'case:selector-after-split|format:gitlab',
                    'case:selector-after-split|format:health',
                    'case:selector-after-split|format:html',
                    'case:selector-after-split|format:json',
                    'case:selector-after-split|format:metrics',
                    'case:selector-after-split|format:sarif',
                    'case:selector-after-split|format:summary',
                    'case:selector-after-split|format:suppressed',
                    'case:selector-after-split|format:text',
                    'case:selector-after-split|format:text-verbose',
                    'case:selector-after-split|show-suppressed',
                    'case:selector-after-split|stderr:baseline-file',
                    'case:selector-after-split|stderr:check:output',
                    'case:selector-after-split|stderr:format:checkstyle',
                    'case:selector-after-split|stderr:format:github',
                    'case:selector-after-split|stderr:format:health',
                    'case:selector-after-split|stderr:format:html',
                    'case:selector-after-split|stderr:format:summary',
                    'case:selector-after-split|stderr:format:text',
                    'case:selector-after-split|stderr:format:text-verbose',
                    'case:selector-after-split|stderr:show-suppressed',
                ],
                FailureClass::VALUE_MISMATCH => [
                    'case:selector-after-split|baseline-file',
                    'case:selector-after-split|check:output',
                    'case:selector-after-split|directives',
                    'case:selector-after-split|format:checkstyle',
                    'case:selector-after-split|format:github',
                    'case:selector-after-split|format:gitlab',
                    'case:selector-after-split|format:health',
                    'case:selector-after-split|format:html',
                    'case:selector-after-split|format:json',
                    'case:selector-after-split|format:metrics',
                    'case:selector-after-split|format:sarif',
                    'case:selector-after-split|format:summary',
                    'case:selector-after-split|format:suppressed',
                    'case:selector-after-split|format:text',
                    'case:selector-after-split|format:text-verbose',
                    'case:selector-after-split|show-suppressed',
                ],
            ],
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
    private static function product(string $case, string $path, string $old, string $replacement, array $failures, ?string $id = null): Control
    {
        return Control::red(
            $id ?? 'corpus-' . $case,
            'the product changes only the ' . $case . ' case',
            Mutation::edit($path, [$old => $replacement], 'perturb the product behaviour exercised by ' . $case),
            self::expectations($failures),
        );
    }
}
