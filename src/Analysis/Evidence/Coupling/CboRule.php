<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Hierarchical rule that checks CBO (Coupling Between Objects) at class and namespace levels.
 *
 * CBO = |Ca ∪ Ce| (union of afferent and efferent couplings)
 * - Low CBO (<14): weakly coupled, easy to test
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled, hard to isolate (error)
 *
 * @qmx-threshold coupling.cbo 23 -- Raw CBO 22, from declaring both its own channel (per-rule) and its shape (ADR 0031, the ChannelShape-typed SHAPE constant) alongside the rest of this hierarchical rule's dependencies; 23 gets one-edge headroom.
 */
#[CliAlias('cbo-warning', 'class.warning')]
#[CliAlias('cbo-error', 'class.error')]
#[CliAlias('cbo-ns-warning', 'namespace.warning')]
#[CliAlias('cbo-ns-error', 'namespace.error')]
final class CboRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'coupling.cbo';
    public const string DOCS_PAGE = 'rules/coupling.md';

    public const int REMEDIATION_MINUTES = 45;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
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
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array
    {
        return [SymbolLevel::Class_, SymbolLevel::Namespace_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Finding>
     */
    public function analyzeLevel(SymbolLevel $level, AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }

        return match ($level) {
            SymbolLevel::Class_ => $this->options->class->isEnabled() ? $this->analyzeClassLevel($context) : [],
            SymbolLevel::Namespace_ => $this->options->namespace->isEnabled() ? $this->analyzeNamespaceLevel($context) : [],
            default => [],
        };
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        $findings = [];

        foreach ($this->getSupportedLevels() as $level) {
            $findings = [...$findings, ...$this->analyzeLevel($level, $context)];
        }

        return $findings;
    }

    /**
     * @return class-string<CboOptions>
     */
    public static function getOptionsClass(): string
    {
        return CboOptions::class;
    }

    /**
     * Both CBO channels report the raw CBO value (`(float) $cbo` — see
     * {@see checkCbo()}) as `metricValue`, judged worse the higher it goes.
     * {@see ClassCboOptions::getSeverity()} and
     * {@see NamespaceCboOptions::getSeverity()} delegate the `>= error`, then
     * `>= warning` comparisons for their respective levels.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            FindingChannel::leveled(self::NAME, SymbolLevel::Class_)->code => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
            FindingChannel::leveled(self::NAME, SymbolLevel::Namespace_)->code => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Namespace_),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $findings = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $finding = $this->classFinding($classInfo, $context, $this->options->class);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function classFinding(SymbolInfo $info, AnalysisContext $context, ClassCboOptions $options): ?Finding
    {
        $subject = $info->subject ?? throw new LogicException('CBO class findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $metrics = $context->metrics->get($subject->toSymbolPath());
        $applicationScope = $options->scope === 'application';
        $metricName = $applicationScope ? MetricName::COUPLING_CBO_APP : MetricName::COUPLING_CBO;
        $cbo = $metrics->get($metricName);
        if ($cbo === null) {
            return null;
        }

        $frameworkCe = $applicationScope ? (int) ($metrics->get(MetricName::COUPLING_CE_FRAMEWORK) ?? 0) : null;

        return $this->checkCbo(
            (int) $cbo,
            $info,
            $subject,
            $options,
            SymbolLevel::Class_,
            $context,
            ['applicationScope' => $applicationScope, 'frameworkCe' => $frameworkCe],
        );
    }

    /**
     * @return list<Finding>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $findings = [];

        foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $finding = $this->namespaceFinding($nsInfo, $context, $this->options->namespace);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function namespaceFinding(SymbolInfo $info, AnalysisContext $context, NamespaceCboOptions $options): ?Finding
    {
        $subject = $info->subject ?? MetricSubject::aggregate($info->symbolPath);
        $metrics = $context->metrics->get($info->symbolPath);
        $classCount = (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0);
        $cbo = $metrics->get(MetricName::COUPLING_CBO);
        if ($classCount < $options->minClassCount || $cbo === null) {
            return null;
        }

        return $this->checkCbo(
            (int) $cbo,
            $info,
            $subject,
            $options,
            SymbolLevel::Namespace_,
            $context,
            ['applicationScope' => false, 'frameworkCe' => null],
        );
    }

    /**
     * Checks CBO threshold for a symbol.
     *
     * @param array{applicationScope: bool, frameworkCe: ?int} $presentation
     */
    private function checkCbo(
        int $cbo,
        SymbolInfo $symbolInfo,
        MetricSubject $subject,
        ClassCboOptions|NamespaceCboOptions $options,
        SymbolLevel $level,
        AnalysisContext $context,
        array $presentation,
    ): ?Finding {
        /** @var ClassCboOptions|NamespaceCboOptions $options */
        $options = $this->getEffectiveOptions($context, $options, $subject);
        $metrics = $context->metrics->get($subject->toSymbolPath());
        $ca = (int) $metrics->require(MetricName::COUPLING_CA);
        $ce = (int) $metrics->require(MetricName::COUPLING_CE);

        $severity = $options->getSeverity($cbo);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->error : $options->warning;
        $code = FindingChannel::leveled(self::NAME, $level)->code;

        return new Finding(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            subject: $subject,
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            code: $code,
            message: $this->buildMessage($cbo, $ca, $ce, $threshold, $presentation['applicationScope'], $presentation['frameworkCe']),
            severity: $severity,
            metricValue: (float) $cbo,
            recommendation: $this->buildRecommendation($cbo, $ca, $ce, $threshold, $symbolInfo->symbolPath, $context, $presentation['applicationScope']),
            threshold: $threshold,
        );
    }

    /**
     * Determines coupling direction and builds a direction-aware finding message.
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
        ?SymbolPath $symbolPath,
        AnalysisContext $context,
        bool $isAppScope,
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

        $topDeps = $this->getTopDependencies($symbolPath, $context);
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
    private function getTopDependencies(?SymbolPath $symbolPath, AnalysisContext $context): string
    {
        $dependencyGraph = $context->dependencyGraph;
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
            $targetKey = $dep->targetLogical()->toCanonical();
            $counts[$targetKey] = ($counts[$targetKey] ?? 0) + 1;
            $targetNames[$targetKey] = $dep->targetLogical()->type ?? $targetKey;
        }

        // Sort by occurrence count descending
        arsort($counts);

        $topKeys = \array_slice(array_keys($counts), 0, 5);
        $topNames = array_map(static fn(string $targetKey): string => $targetNames[$targetKey], $topKeys);

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

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
