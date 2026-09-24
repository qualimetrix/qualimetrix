<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Hierarchical rule that checks CBO (Coupling Between Objects) at class and namespace levels.
 *
 * CBO = |Ca ∪ Ce| (union of afferent and efferent couplings)
 * - Low CBO (<14): weakly coupled, easy to test
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled, hard to isolate (error)
 *
 * Besides the published `coupling.cbo` (or `coupling.cbo-app` under
 * `scope: application`) value, also reads `coupling.ca` and `coupling.ce`
 * to describe the coupling direction in the message/recommendation, plus
 * `coupling.ce-framework` to report the excluded-framework-classes count
 * under the application scope.
 *
 * @qmx-threshold coupling.cbo 22 -- Raw CBO 21: this hierarchical rule's own dependencies plus
 *                the per-rule channel, shape and judged-metric declarations every producer must
 *                name (ADR 0031, ADR 0046). Those declaration types are metadata the rule states
 *                about itself — the levels it reports at, the metrics it judges — not
 *                collaborators it calls, so CBO counts as entanglement what is really this class
 *                describing itself; the count would fall by naming the same facts in strings, which
 *                is worse. 22 gets one-edge headroom.
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
     * Both levels of the channel report the raw CBO value (`(float) $cbo` —
     * see {@see checkCbo()}) as `metricValue`, judged worse the higher it
     * goes. {@see ClassCboOptions::getSeverity()} and
     * {@see NamespaceCboOptions::getSeverity()} delegate the `>= error`, then
     * `>= warning` comparisons for their respective levels.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::judging(
                WorseDirection::Higher,
                JudgedMetrics::of(
                    MetricName::COUPLING_CBO,
                    MetricName::COUPLING_CBO_APP,
                ),
                SymbolLevel::Class_,
                SymbolLevel::Namespace_,
            ),
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
            $context,
            ['applicationScope' => $applicationScope, 'frameworkCe' => $frameworkCe, 'namespaceLevel' => false],
        );
    }

    /**
     * Judges leaf namespaces only. A parent's CBO is taken over its whole
     * subtree (the region its Ca and Ce are counted over), so it grows with
     * the subtree and the namespace thresholds do not model it; the value is
     * still published. A namespace declaring classes beside sub-namespaces is
     * a parent too: its number is the subtree's, not its own classes'.
     *
     * @return list<Finding>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $findings = [];
        $namespaces = iterator_to_array($context->metrics->all(SymbolLevel::Namespace_), false);
        $parents = $this->parentNamespaces($namespaces);

        foreach ($namespaces as $nsInfo) {
            if (isset($parents[(string) $nsInfo->symbolPath->namespace])) {
                continue;
            }

            $finding = $this->namespaceFinding($nsInfo, $context, $this->options->namespace);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Every proper ancestor of a namespace in the run: exactly the namespaces
     * with a sub-namespace beneath them. The global namespace is nobody's.
     *
     * @param list<SymbolInfo> $namespaces
     *
     * @return array<string, true>
     */
    private function parentNamespaces(array $namespaces): array
    {
        $parents = [];

        foreach ($namespaces as $info) {
            $namespace = (string) $info->symbolPath->namespace;

            while (($separator = strrpos($namespace, '\\')) !== false) {
                $namespace = substr($namespace, 0, $separator);
                $parents[$namespace] = true;
            }
        }

        return $parents;
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
            $context,
            ['applicationScope' => false, 'frameworkCe' => null, 'namespaceLevel' => true],
        );
    }

    /**
     * Checks CBO threshold for a symbol.
     *
     * @param array{applicationScope: bool, frameworkCe: ?int, namespaceLevel: bool} $presentation
     */
    private function checkCbo(
        int $cbo,
        SymbolInfo $symbolInfo,
        MetricSubject $subject,
        ClassCboOptions|NamespaceCboOptions $options,
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
        // Namespace CBO counts namespaces while its Ca and Ce count classes;
        // without the unit the union reads smaller than its own parts.
        $cboText = $presentation['namespaceLevel'] ? $cbo . ' namespaces' : (string) $cbo;

        return new Finding(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            subject: $subject,
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            code: self::NAME,
            message: CboFindingText::message($cboText, $ca, $ce, $threshold, $presentation['applicationScope'], $presentation['frameworkCe']),
            severity: $severity,
            metricValue: (float) $cbo,
            recommendation: CboFindingText::recommendation($cboText, $ca, $ce, $threshold, $symbolInfo->symbolPath, $context, $presentation),
            threshold: $threshold,
        );
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
