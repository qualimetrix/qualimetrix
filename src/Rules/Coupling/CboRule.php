<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Coupling;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\HierarchicalRuleInterface;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Hierarchical rule that checks CBO (Coupling Between Objects) at class and namespace levels.
 *
 * CBO = |Ca ∪ Ce| (union of afferent and efferent couplings)
 * - Low CBO (<14): weakly coupled, easy to test
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled, hard to isolate (error)
 */
final class CboRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'coupling.cbo';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks CBO (Coupling Between Objects) at class and namespace levels';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Coupling;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::COUPLING_CBO, MetricName::COUPLING_CA, MetricName::COUPLING_CE, MetricName::COUPLING_CBO_APP, MetricName::COUPLING_CE_FRAMEWORK];
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Class_, RuleLevel::Namespace_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(RuleLevel $level, AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            RuleLevel::Class_ => $this->analyzeClassLevel($context),
            RuleLevel::Namespace_ => $this->analyzeNamespaceLevel($context),
            default => [],
        };
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options instanceof CboOptions && $this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<CboOptions>
     */
    public static function getOptionsClass(): string
    {
        return CboOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'cbo-warning' => 'class.warning',
            'cbo-error' => 'class.error',
            'cbo-ns-warning' => 'namespace.warning',
            'cbo-ns-error' => 'namespace.error',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        // Determine which metric to use based on scope
        $isAppScope = $classOptions->scope === 'application';
        $metricName = $isAppScope
            ? MetricName::COUPLING_CBO_APP
            : MetricName::COUPLING_CBO;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            $cbo = $metrics->get($metricName);
            if ($cbo === null) {
                continue;
            }

            $cboValue = (int) $cbo;
            $ceFramework = $isAppScope ? (int) ($metrics->get(MetricName::COUPLING_CE_FRAMEWORK) ?? 0) : null;
            $violation = $this->checkCbo($cboValue, $classInfo, $metrics, $classOptions, RuleLevel::Class_, $context, $context->dependencyGraph, $isAppScope, $ceFramework);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $namespaceOptions = $this->options->namespace;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $metrics = $context->metrics->get($nsInfo->symbolPath);

            // Skip namespaces with too few classes
            $classCount = (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0);
            if ($classCount < $namespaceOptions->minClassCount) {
                continue;
            }

            $cbo = $metrics->get(MetricName::COUPLING_CBO);
            if ($cbo === null) {
                continue;
            }

            $cboValue = (int) $cbo;
            $violation = $this->checkCbo($cboValue, $nsInfo, $metrics, $namespaceOptions, RuleLevel::Namespace_, $context);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Checks CBO threshold for a symbol.
     */
    private function checkCbo(
        int $cbo,
        SymbolInfo $symbolInfo,
        MetricBag $metrics,
        ClassCboOptions|NamespaceCboOptions $options,
        RuleLevel $level,
        AnalysisContext $context,
        ?DependencyGraphInterface $dependencyGraph = null,
        bool $isAppScope = false,
        ?int $ceFramework = null,
    ): ?Violation {
        /** @var ClassCboOptions|NamespaceCboOptions $options */
        $options = $this->getEffectiveOptions($context, $options, $symbolInfo->file, $symbolInfo->line ?? 1);
        $ca = (int) $metrics->require(MetricName::COUPLING_CA);
        $ce = (int) $metrics->require(MetricName::COUPLING_CE);

        $violationCode = self::NAME . ($level === RuleLevel::Namespace_ ? '.namespace' : '.class');

        if ($cbo >= $options->error) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: $violationCode,
                message: $this->buildMessage($cbo, $ca, $ce, $options->error, $isAppScope, $ceFramework),
                severity: Severity::Error,
                metricValue: (float) $cbo,
                level: $level,
                recommendation: $this->buildRecommendation($cbo, $ca, $ce, $options->error, $symbolInfo->symbolPath, $dependencyGraph, $isAppScope),
                threshold: $options->error,
            );
        }

        if ($cbo >= $options->warning) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: $violationCode,
                message: $this->buildMessage($cbo, $ca, $ce, $options->warning, $isAppScope, $ceFramework),
                severity: Severity::Warning,
                metricValue: (float) $cbo,
                level: $level,
                recommendation: $this->buildRecommendation($cbo, $ca, $ce, $options->warning, $symbolInfo->symbolPath, $dependencyGraph, $isAppScope),
                threshold: $options->warning,
            );
        }

        return null;
    }

    /**
     * Determines coupling direction and builds a direction-aware violation message.
     *
     * When $isAppScope is true, labels the metric as "CBO_APP" and appends
     * framework exclusion count so users understand the decomposition.
     */
    private function buildMessage(int $cbo, int $ca, int $ce, int $threshold, bool $isAppScope = false, ?int $ceFramework = null): string
    {
        $direction = $this->getCouplingDirection($ca, $ce);
        $label = $isAppScope ? 'CBO_APP' : 'CBO';
        $frameworkSuffix = $isAppScope && $ceFramework !== null
            ? \sprintf(', framework: %d classes excluded', $ceFramework)
            : '';

        return match ($direction) {
            'efferent' => \sprintf(
                'Efferent coupling too high: depends on %d classes (%s: %d, threshold: %d%s)',
                $ce,
                $label,
                $cbo,
                $threshold,
                $frameworkSuffix,
            ),
            'afferent' => \sprintf(
                'Afferent coupling too high: %d classes depend on this (%s: %d, threshold: %d%s)',
                $ca,
                $label,
                $cbo,
                $threshold,
                $frameworkSuffix,
            ),
            default => \sprintf(
                'Coupling too high: %d inbound + %d outbound (%s: %d, threshold: %d%s)',
                $ca,
                $ce,
                $label,
                $cbo,
                $threshold,
                $frameworkSuffix,
            ),
        };
    }

    /**
     * Builds a direction-aware recommendation, optionally including top dependencies.
     */
    private function buildRecommendation(
        int $cbo,
        int $ca,
        int $ce,
        int $threshold,
        ?SymbolPath $symbolPath = null,
        ?DependencyGraphInterface $dependencyGraph = null,
        bool $isAppScope = false,
    ): string {
        $direction = $this->getCouplingDirection($ca, $ce);
        $label = $isAppScope ? 'CBO_APP' : 'CBO';

        $base = match ($direction) {
            'efferent' => \sprintf(
                '%s: %d (threshold: %d) — extract dependencies to reduce outbound coupling',
                $label,
                $cbo,
                $threshold,
            ),
            'afferent' => \sprintf(
                '%s: %d (threshold: %d) — this class is a coupling magnet, consider if it is a healthy abstraction point',
                $label,
                $cbo,
                $threshold,
            ),
            default => \sprintf(
                '%s: %d (threshold: %d) — reduce both inbound and outbound coupling',
                $label,
                $cbo,
                $threshold,
            ),
        };

        $topDeps = $this->getTopDependencies($symbolPath, $dependencyGraph);
        if ($topDeps !== '') {
            return $topDeps . '. ' . $base;
        }

        return $base;
    }

    /**
     * Returns a formatted string of top-5 efferent dependencies for a class, sorted by occurrence count.
     *
     * Only works for class-level SymbolPaths when the dependency graph is available.
     */
    private function getTopDependencies(?SymbolPath $symbolPath, ?DependencyGraphInterface $dependencyGraph): string
    {
        if ($symbolPath === null || $dependencyGraph === null) {
            return '';
        }

        if ($symbolPath->getType() !== SymbolType::Class_) {
            return '';
        }

        $dependencies = $dependencyGraph->getClassDependencies($symbolPath);
        if ($dependencies === []) {
            return '';
        }

        // Count occurrences per target class (a class may be referenced multiple times)
        $counts = [];
        $targetNames = [];
        foreach ($dependencies as $dep) {
            $targetKey = $dep->target->toCanonical();
            $counts[$targetKey] = ($counts[$targetKey] ?? 0) + 1;
            $targetNames[$targetKey] = $dep->target->type ?? $targetKey;
        }

        // Sort by occurrence count descending
        arsort($counts);

        // Take top 5 short class names
        $topNames = [];
        $i = 0;
        foreach ($counts as $targetCanonical => $_count) {
            if ($i >= 5) {
                break;
            }

            $topNames[] = $targetNames[$targetCanonical];
            $i++;
        }

        if ($topNames === []) {
            return '';
        }

        return 'Top dependencies: ' . implode(', ', $topNames);
    }

    /**
     * Determines coupling direction: 'afferent', 'efferent', or 'balanced'.
     *
     * Uses a 2:1 ratio threshold: a direction dominates when it accounts
     * for more than twice the other direction.
     */
    private function getCouplingDirection(int $ca, int $ce): string
    {
        if ($ca > $ce * 2) {
            return 'afferent';
        }

        if ($ce > $ca * 2) {
            return 'efferent';
        }

        return 'balanced';
    }
}
