<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
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
 * A class is judged on the published `coupling.cbo` (or `coupling.cbo-app`
 * under `scope: application`), a namespace on `coupling.cbo-own`. Besides it
 * the rule reads the Ca and Ce of the same scope (`coupling.ca`/`coupling.ce`,
 * or their `-own` pair) to describe the coupling direction in the
 * message/recommendation, plus `coupling.ce-framework` to report the
 * excluded-framework-classes count under the application scope.
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

    public static function getDescription(): string
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
                    MetricName::COUPLING_CBO_OWN,
                ),
                SymbolLevel::Class_,
                SymbolLevel::Namespace_,
            )->withGates(
                new PopulationGate('class-coordinate', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                new PopulationGate('class-cbo', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyPresent('class-cbo', ['all' => MetricName::COUPLING_CBO, 'application' => MetricName::COUPLING_CBO_APP]), 'The selected CBO publication is unavailable.'),
                new PopulationGate('own-classes', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', new KeyThreshold('own-classes', [MetricName::SIZE_CLASS_COUNT], '>=', 'own-classes', 'zero', true), 'Own classes are below the configured minimum.'),
                new PopulationGate('own-cbo', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', new KeyPresent('own-cbo', [MetricName::COUPLING_CBO_OWN]), 'Own CBO was not published.'),
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
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allClassDeclarations() as $classInfo) {
            $finding = $this->classFinding($classInfo, $context, $this->options->class, $declaration);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function classFinding(SymbolInfo $info, AnalysisContext $context, ClassCboOptions $options, ChannelDeclaration $declaration): ?Finding
    {
        $subject = $info->subject ?? throw new LogicException('CBO class findings require an exact class declaration subject');
        $metrics = null;
        $applicationScope = false;
        if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::subject($subject), $declaration, (function () use ($context, $subject, &$metrics, &$options, &$applicationScope): iterable {
            yield GateInput::kind('class-coordinate', $subject->toSymbolPath()->getType());
            $options = $this->getEffectiveOptions($context, $options, $subject);
            $metrics = $context->metrics->getSubject($subject);
            $applicationScope = $options->scope === 'application';
            yield GateInput::metrics('class-cbo', $metrics, selector: $options->scope);
        })())) {
            return null;
        }
        $metricName = $applicationScope ? MetricName::COUPLING_CBO_APP : MetricName::COUPLING_CBO;
        $cbo = $metrics->get($metricName);

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
     * Judges every namespace on the coupling of its own declarations,
     * `coupling.cbo-own`: the population a project fold reads, where each
     * declaration belongs to exactly one namespace. The published
     * `coupling.cbo` of a namespace is taken over its whole subtree, grows with
     * it, and is not judged — nor is its region, which is the namespace alone
     * or its subtree depending on which sub-namespaces the run holds. The own
     * scope's boundary does not move with that, so which namespaces are judged
     * does not depend on the run's paths. The value judged does, as Ca,
     * instability and class rank do: it counts the dependencies of the code
     * analysed, so a run that leaves out a dependent — reported as a narrowed
     * project scope — can judge a lower value.
     * `min_class_count` counts the namespace's own classes, for the same
     * reason.
     *
     * @return list<Finding>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $findings = [];
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->all(SymbolLevel::Namespace_) as $nsInfo) {
            $finding = $this->namespaceFinding($nsInfo, $context, $this->options->namespace, $declaration);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function namespaceFinding(SymbolInfo $info, AnalysisContext $context, NamespaceCboOptions $options, ChannelDeclaration $declaration): ?Finding
    {
        $subject = $info->subject ?? MetricSubject::aggregate($info->symbolPath);
        $metrics = $context->metrics->get($info->symbolPath);
        $options = $this->getEffectiveOptions($context, $options, $subject);
        $metrics->get(MetricName::SIZE_CLASS_COUNT);
        $cbo = $metrics->get(MetricName::COUPLING_CBO_OWN);
        if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Namespace_, PopulationIdentity::aggregate($info->symbolPath), $declaration, (static function () use ($metrics, $options): iterable {
            yield GateInput::metrics('own-classes', $metrics, $options->minClassCount);
            yield GateInput::metrics('own-cbo', $metrics);
        })())) {
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
        $metrics = $presentation['namespaceLevel']
            ? $context->metrics->get($symbolInfo->symbolPath)
            : $context->metrics->getSubject($subject);
        // A namespace is judged on its own scope, so its direction is read there too.
        $ca = (int) $metrics->require($presentation['namespaceLevel'] ? MetricName::COUPLING_CA_OWN : MetricName::COUPLING_CA);
        $ce = (int) $metrics->require($presentation['namespaceLevel'] ? MetricName::COUPLING_CE_OWN : MetricName::COUPLING_CE);

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
