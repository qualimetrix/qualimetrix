<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Instability;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Analysis\Finding\Rule\AbstractRule;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Hierarchical rule that checks instability at class and namespace levels.
 *
 * Instability = Ce / (Ca + Ce), range [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 *
 * Classes/namespaces with high instability are fragile — they depend on many
 * other components, so changes in dependencies may break them.
 *
 * A class is judged on `coupling.instability`, a namespace on
 * `coupling.instability-own`. Besides it the rule reads the matching Ca and
 * Ce (`coupling.ca`/`coupling.ce`, or their `-own` pair) to report the
 * underlying counts in the finding's message.
 */
#[CliAlias('instability-class-warning', 'class.max_warning')]
#[CliAlias('instability-class-error', 'class.max_error')]
#[CliAlias('instability-ns-warning', 'namespace.max_warning')]
#[CliAlias('instability-ns-error', 'namespace.max_error')]
final class InstabilityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'coupling.instability';

    public const string DESCRIPTION = 'Checks instability at class and namespace levels';
    public const string DOCS_PAGE = 'rules/coupling.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;

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
        if (!$this->options instanceof InstabilityOptions) {
            return [];
        }

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            SymbolLevel::Class_ => $this->analyzeClassLevel($context),
            SymbolLevel::Namespace_ => $this->analyzeNamespaceLevel($context),
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
            if ($this->options instanceof InstabilityOptions && $this->options->isLevelEnabled($level)) {
                $findings = [...$findings, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $findings;
    }

    /**
     * @return class-string<InstabilityOptions>
     */
    public static function getOptionsClass(): string
    {
        return InstabilityOptions::class;
    }

    /**
     * Both levels of the channel report the instability value
     * (`$instabilityValue` — see {@see analyzeClassLevel()} and
     * {@see analyzeNamespaceLevel()}) as `metricValue`, judged worse the
     * higher it goes: {@see ClassInstabilityOptions::getSeverity()}'s
     * `$instability >= $this->maxError` / `$instability >=
     * $this->maxWarning` at the class level, and
     * {@see NamespaceInstabilityOptions::getSeverity()}'s `$instability >=
     * $this->maxError` / `$instability >= $this->maxWarning`
     * at the namespace level.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => self::judgingHigher(
                [MetricName::COUPLING_INSTABILITY, MetricName::COUPLING_INSTABILITY_OWN],
                SymbolLevel::Class_,
                SymbolLevel::Namespace_,
            )->withGates(
                self::populationGate('class-coordinate', self::NAME, SymbolLevel::Class_, 'declaration', self::kindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                self::populationGate('class-instability', self::NAME, SymbolLevel::Class_, 'declaration', self::keyPresent('class-instability', [MetricName::COUPLING_INSTABILITY]), 'Class instability was not published.'),
                self::populationGate('class-afferent', self::NAME, SymbolLevel::Class_, 'declaration', self::keyThreshold('class-afferent', [MetricName::COUPLING_CA], '>=', 'class-afferent', 'zero', true), 'Class afferent coupling is below its minimum.'),
                self::populationGate('own-types', self::NAME, SymbolLevel::Namespace_, 'namespace', self::keyThreshold('own-types', [MetricName::SIZE_CLASS_COUNT, MetricName::SIZE_TRAIT_COUNT, MetricName::SIZE_INTERFACE_COUNT, MetricName::SIZE_IMPLEMENTING_ENUM_COUNT], '>=', 'own-types', 'zero', true), 'The own type population is below its minimum.'),
                self::populationGate('own-package', self::NAME, SymbolLevel::Namespace_, 'namespace', self::keyThreshold('own-package', [MetricName::SIZE_CLASS_COUNT, MetricName::SIZE_TRAIT_COUNT, MetricName::SIZE_INTERFACE_COUNT, MetricName::SIZE_IMPLEMENTING_ENUM_COUNT], '>', 0, 'zero', true), 'The namespace declares no class, trait, interface or implementing enum of its own.'),
                self::populationGate('own-instability', self::NAME, SymbolLevel::Namespace_, 'namespace', self::keyPresent('own-instability', [MetricName::COUPLING_INSTABILITY_OWN]), 'Own instability was not published.'),
                self::populationGate('own-afferent', self::NAME, SymbolLevel::Namespace_, 'namespace', self::keyThreshold('own-afferent', [MetricName::COUPLING_CA_OWN], '>=', 'own-afferent', 'zero', true), 'Own afferent coupling is below its minimum.'),
            ),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof InstabilityOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        $findings = [];
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allClassDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Instability class findings require an exact class declaration subject');
            $finding = $this->classFinding(new Location($classInfo->file, $classInfo->line), $subject, $context, $classOptions, $declaration);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function classFinding(
        Location $location,
        MetricSubject $subject,
        AnalysisContext $context,
        ClassInstabilityOptions $options,
        ChannelDeclaration $declaration,
    ): ?Finding {
        $metrics = null;
        if (!$this->admitSubject($context, $subject, $declaration, (function () use ($context, $subject, &$metrics, &$options): iterable {
            yield GateInput::kind('class-coordinate', $subject->toSymbolPath()->getType());
            $options = $this->getEffectiveOptions($context, $options, $subject);
            $metrics = $context->metrics->getSubject($subject);
            yield GateInput::metrics('class-instability', $metrics);
            yield GateInput::metrics('class-afferent', $metrics, $options->minAfferent);
        })(), SymbolLevel::Class_)) {
            return null;
        }
        $instability = $metrics->get(MetricName::COUPLING_INSTABILITY);
        $ca = (int) ($metrics->get(MetricName::COUPLING_CA) ?? 0);

        $instabilityValue = (float) $instability;
        /** @var ClassInstabilityOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($instabilityValue);
        if ($severity === null) {
            return null;
        }

        $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);
        $threshold = $severity === Severity::Error ? $effectiveOptions->maxError : $effectiveOptions->maxWarning;
        [$valueText, $thresholdText, $rounded] = ThresholdCrossing::display($instabilityValue, $threshold);

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                'Instability is %s (Ca=%d, Ce=%d), ' . ThresholdCrossing::of($instabilityValue, $threshold)->value . ' threshold of %s%s. Reduce outgoing dependencies',
                $valueText,
                $ca,
                $ce,
                $thresholdText,
                $rounded ? ' (display rounded)' : '',
            ),
            severity: $severity,
            metricValue: $instabilityValue,
            recommendation: \sprintf('Instability: %s (threshold: %s%s) — package is highly unstable', $valueText, $thresholdText, $rounded ? ', display rounded' : ''),
            threshold: $threshold,
        );
    }

    /**
     * Judges every namespace on the instability of its own declarations,
     * `coupling.instability-own`, with the population counted over the types
     * it declares itself, as CBO and distance do. The subtree value
     * `coupling.instability` folds in every outward dependency of the
     * sub-namespaces while few dependents reach the parent itself, so a
     * context root that declares nothing reads close to 1 by construction; it
     * stays published and is not judged. A namespace declaring no type has no
     * own instability and is never judged. Nor is one declaring only bare
     * enums, whatever `min_type_count` says: it has no own abstractness and
     * so no distance, and a namespace is a package for both rules or for
     * neither.
     *
     * @return list<Finding>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof InstabilityOptions) {
            return [];
        }
        $namespaceOptions = $this->options->namespace;

        $findings = [];
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->all(SymbolLevel::Namespace_) as $nsInfo) {
            $subject = $nsInfo->subject ?? MetricSubject::aggregate($nsInfo->symbolPath);
            $metrics = $context->metrics->get($nsInfo->symbolPath);

            $populationOptions = $this->getEffectiveOptions($context, $namespaceOptions, $subject);
            if (!$this->admitSubject($context, MetricSubject::aggregate($nsInfo->symbolPath), $declaration, (static function () use ($metrics, $populationOptions): iterable {
                yield GateInput::metrics('own-types', $metrics, $populationOptions->minTypeCount);
                yield GateInput::metrics('own-package', $metrics);
                yield GateInput::metrics('own-instability', $metrics);
                yield GateInput::metrics('own-afferent', $metrics, $populationOptions->minAfferent);
            })(), SymbolLevel::Namespace_, 'namespace')) {
                continue;
            }
            $instabilityValue = (float) $metrics->require(MetricName::COUPLING_INSTABILITY_OWN);

            /** @var NamespaceInstabilityOptions $effectiveNsOptions */
            $effectiveNsOptions = $this->getEffectiveOptions($context, $namespaceOptions, $subject);
            $severity = $effectiveNsOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ca = (int) $metrics->require(MetricName::COUPLING_CA_OWN);
                $ce = (int) $metrics->require(MetricName::COUPLING_CE_OWN);

                $threshold = $severity === Severity::Error ? $effectiveNsOptions->maxError : $effectiveNsOptions->maxWarning;
                [$valueText, $thresholdText, $rounded] = ThresholdCrossing::display($instabilityValue, $threshold);

                $findings[] = new Finding(
                    location: new Location($nsInfo->file, $nsInfo->line),
                    subject: $subject,
                    symbolPath: $nsInfo->symbolPath,
                    ruleName: $this->getName(),
                    code: self::NAME,
                    message: \sprintf(
                        'Instability is %s (Ca=%d, Ce=%d), ' . ThresholdCrossing::of($instabilityValue, $threshold)->value . ' threshold of %s%s. Reduce outgoing dependencies',
                        $valueText,
                        $ca,
                        $ce,
                        $thresholdText,
                        $rounded ? ' (display rounded)' : '',
                    ),
                    severity: $severity,
                    metricValue: $instabilityValue,
                    recommendation: \sprintf('Instability: %s (threshold: %s%s) — package is highly unstable', $valueText, $thresholdText, $rounded ? ', display rounded' : ''),
                    threshold: $threshold,
                );
            }
        }

        return $findings;
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
