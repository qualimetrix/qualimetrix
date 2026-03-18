<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Hierarchical rule that checks CBO (Coupling Between Objects) at class and namespace levels.
 *
 * CBO = Ca + Ce (afferent + efferent coupling)
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
        return [MetricName::COUPLING_CBO, MetricName::COUPLING_CA, MetricName::COUPLING_CE];
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

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            $cbo = $metrics->get(MetricName::COUPLING_CBO);
            if ($cbo === null) {
                continue;
            }

            $cboValue = (int) $cbo;
            $violation = $this->checkCbo($cboValue, $classInfo, $metrics, $classOptions, RuleLevel::Class_, $context->dependencyGraph);
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
            $classCount = (int) ($metrics->get(MetricName::SIZE_CLASS_COUNT . '.sum') ?? 0);
            if ($classCount < $namespaceOptions->minClassCount) {
                continue;
            }

            $cbo = $metrics->get(MetricName::COUPLING_CBO);
            if ($cbo === null) {
                continue;
            }

            $cboValue = (int) $cbo;
            $violation = $this->checkCbo($cboValue, $nsInfo, $metrics, $namespaceOptions, RuleLevel::Namespace_);
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
        ?DependencyGraphInterface $dependencyGraph = null,
    ): ?Violation {
        $ca = (int) ($metrics->get(MetricName::COUPLING_CA) ?? 0);
        $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);

        $violationCode = self::NAME . ($level === RuleLevel::Namespace_ ? '.namespace' : '.class');

        if ($cbo >= $options->error) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: $violationCode,
                message: $this->buildMessage($cbo, $ca, $ce, $options->error),
                severity: Severity::Error,
                metricValue: (float) $cbo,
                level: $level,
                recommendation: $this->buildRecommendation($cbo, $ca, $ce, $options->error, $symbolInfo->symbolPath, $dependencyGraph),
                threshold: $options->error,
            );
        }

        if ($cbo >= $options->warning) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: $violationCode,
                message: $this->buildMessage($cbo, $ca, $ce, $options->warning),
                severity: Severity::Warning,
                metricValue: (float) $cbo,
                level: $level,
                recommendation: $this->buildRecommendation($cbo, $ca, $ce, $options->warning, $symbolInfo->symbolPath, $dependencyGraph),
                threshold: $options->warning,
            );
        }

        return null;
    }

    /**
     * Determines coupling direction and builds a direction-aware violation message.
     */
    private function buildMessage(int $cbo, int $ca, int $ce, int $threshold): string
    {
        $direction = $this->getCouplingDirection($ca, $ce);

        return match ($direction) {
            'efferent' => \sprintf(
                'Efferent coupling too high: depends on %d classes (CBO: %d, threshold: %d)',
                $ce,
                $cbo,
                $threshold,
            ),
            'afferent' => \sprintf(
                'Afferent coupling too high: %d classes depend on this (CBO: %d, threshold: %d)',
                $ca,
                $cbo,
                $threshold,
            ),
            default => \sprintf(
                'Coupling too high: %d inbound + %d outbound (CBO: %d, threshold: %d)',
                $ca,
                $ce,
                $cbo,
                $threshold,
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
    ): string {
        $direction = $this->getCouplingDirection($ca, $ce);

        $base = match ($direction) {
            'efferent' => \sprintf(
                'CBO: %d (threshold: %d) — extract dependencies to reduce outbound coupling',
                $cbo,
                $threshold,
            ),
            'afferent' => \sprintf(
                'CBO: %d (threshold: %d) — this class is a coupling magnet, consider if it is a healthy abstraction point',
                $cbo,
                $threshold,
            ),
            default => \sprintf(
                'CBO: %d (threshold: %d) — reduce both inbound and outbound coupling',
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
