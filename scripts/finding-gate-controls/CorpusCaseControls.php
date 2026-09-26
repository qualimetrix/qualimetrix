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
            [FailureClass::CASE_OUTCOME_MISMATCH],
        );
    }

    public static function scopedLayers(): Control
    {
        return self::product(
            'scoped-layers',
            'src/Analysis/Policy/Architecture/LayerViolation/LayerDeclarationValidator.php',
            '$judgesAbsence = $context->coversProjectScope;',
            '$judgesAbsence = true;',
            [FailureClass::FINDING_COUNT_MISMATCH],
        );
    }

    public static function duplicationSize(): Control
    {
        return self::product(
            'duplication-size',
            'src/Analysis/Evidence/Duplication/DuplicateBlockFinder.php',
            '$longest = max(array_map(function (int $copy) use ($length): int {',
            '$longest = min(array_map(function (int $copy) use ($length): int {',
            [FailureClass::FINDING_COUNT_MISMATCH],
        );
    }

    public static function unknownScope(): Control
    {
        return self::product(
            'scoped-layers',
            'src/Analysis/Run/Configuration/ProjectScopeState.php',
            'return $this !== self::Narrowed;',
            'return $this === self::Covered;',
            [FailureClass::SURFACE_MISMATCH],
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
            [FailureClass::SURFACE_MISMATCH],
        );
    }

    public static function thresholdRaising(): Control
    {
        return self::product(
            'threshold-raising',
            'src/Analysis/Evidence/Design/GodClass/GodClassOptions.php',
            'minCriteria: $warning !== null ? (int) $warning : $this->minCriteria,',
            'minCriteria: $warning !== null ? min((int) $warning, $this->minCriteria) : $this->minCriteria,',
            [FailureClass::FINDING_COUNT_MISMATCH],
        );
    }

    public static function directivePlacement(): Control
    {
        return self::product(
            'directive-placement',
            'src/Analysis/Policy/Inline/Extraction/UnattachedComments.php',
            'return $owner;',
            'return null;',
            [FailureClass::SURFACE_MISMATCH],
        );
    }

    public static function computedCrossLevel(): Control
    {
        return self::product(
            'computed-cross-level',
            'src/Analysis/Evidence/ComputedMetrics/ComputedMetricFormulaValidator.php',
            '$byName[$key]->hasLevel($level)',
            '$byName[$key]->hasLevel($byName[$key]->levels[0])',
            [FailureClass::SURFACE_MISMATCH],
        );
    }

    public static function stderrWarning(): Control
    {
        return self::product(
            'stderr-warning',
            'src/Analysis/Evidence/Design/Inheritance/DitGlobalCollector.php',
            '$this->logger->warning(\sprintf(',
            '$this->logger->debug(\sprintf(',
            [FailureClass::SURFACE_MISMATCH],
        );
    }

    public static function warningClockRow(): Control
    {
        $row = 'stderr' . "\t" . Normalization::WARNING_TIME_PATTERN . "\tline-regex\t" . Normalization::MEASURED_REASON . "\n";
        return Control::red(
            'corpus-warning-clock-row-missing',
            'the warning clock remains a compared field when its public normalization row is removed',
            Mutation::edit('finding-gate/normalization.tsv', [$row => ''], 'remove only the generic warning-clock exclusion'),
            [new Expectation(FailureClass::NONDETERMINISM_UNDECLARED, 'case:stderr-warning|stderr:'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:stderr-warning|stderr:')],
        );
    }

    public static function baselineCycle(): Control
    {
        return self::product(
            'baseline-cycle',
            'src/Analysis/Policy/Baseline/Filter/BaselineCeilingStage.php',
            '? $finding->reportedAsBreach($verdict->breachedLevel)',
            '? $finding',
            [FailureClass::SURFACE_MISMATCH],
        );
    }

    public static function parallelFiles(): Control
    {
        return self::product(
            'parallel-files',
            'src/Infrastructure/Parallel/Strategy/AmphpParallelStrategy.php',
            'array_push($results, ...$batchResults);',
            'array_push($results, ...array_slice($batchResults, 1));',
            [FailureClass::SURFACE_MISMATCH],
        );
    }

    public static function selectorAfterSplit(): Control
    {
        return self::product(
            'selector-after-split',
            'src/Infrastructure/Console/RuleInputValidator.php',
            "\$selector === '' || !\$this->ruleSelector->matchesKnownIn(\$selector, \$producers, \$channels)",
            "\$selector === ''",
            [FailureClass::CASE_OUTCOME_MISMATCH],
        );
    }

    /** @param list<string> $failures */
    private static function product(string $case, string $path, string $old, string $replacement, array $failures, ?string $id = null): Control
    {
        return Control::red(
            $id ?? 'corpus-' . $case,
            'the product changes only the ' . $case . ' case',
            Mutation::edit($path, [$old => $replacement], 'perturb the product behaviour exercised by ' . $case),
            array_map(static fn(string $failure): Expectation => new Expectation($failure, $case), $failures),
        );
    }
}
