<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks if classes have too many properties.
 *
 * Too many properties may indicate a God Class that violates the Single Responsibility Principle.
 *
 * Besides the published `size.property-count` value, also reads
 * `design.is-readonly` and `design.is-promoted-properties-only` to exclude
 * DTOs when configured.
 */
#[CliAlias('property-count-warning', 'warning')]
#[CliAlias('property-count-error', 'error')]
#[CliAlias('property-exclude-readonly', 'excludeReadonly')]
#[CliAlias('property-exclude-promoted-only', 'excludePromotedOnly')]
final class PropertyCountRule extends AbstractRule
{
    public const string NAME = 'size.property-count';
    public const string DOCS_PAGE = 'rules/size.md';

    public const int REMEDIATION_MINUTES = 15;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks if classes have too many properties';
    }

    /**
     * @return class-string<PropertyCountOptions>
     */
    public static function getOptionsClass(): string
    {
        return PropertyCountOptions::class;
    }

    /**
     * `size.property-count` reports the class's property count
     * (`$propertyCountValue` — see the emission above) as `metricValue`,
     * judged worse the higher it goes:
     * {@see PropertyCountOptions::getSeverity()}'s `$value >= $this->error`
     * / `$value >= $this->warning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => self::judgingHigher(
                [MetricName::SIZE_PROPERTY_COUNT],
                SymbolLevel::Class_,
            )->withGates(
                self::populationGate('class-coordinate', self::NAME, SymbolLevel::Class_, 'declaration', self::kindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                self::populationGate('class-value', self::NAME, SymbolLevel::Class_, 'declaration', self::keyPresent('class-value', [MetricName::SIZE_PROPERTY_COUNT]), 'The class metric was not published.'),
                self::populationGate('exclude-readonly', self::NAME, SymbolLevel::Class_, 'declaration', self::flagExcludes('exclude-readonly', MetricName::DESIGN_IS_READONLY, 1, true), 'The configured class exclusion applies.'),
                self::populationGate('exclude-promoted-only', self::NAME, SymbolLevel::Class_, 'declaration', self::flagExcludes('exclude-promoted-only', MetricName::DESIGN_IS_PROMOTED_PROPERTIES_ONLY, 1, true), 'The configured class exclusion applies.'),
            ),
        ];
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof PropertyCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allClassDeclarations() as $classInfo) {
            $finding = $this->findingForClass($classInfo, $context, $this->options, $declaration);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function findingForClass(
        SymbolInfo $classInfo,
        AnalysisContext $context,
        PropertyCountOptions $options,
        ChannelDeclaration $declaration,
    ): ?Finding {
        $subject = $classInfo->subject ?? throw new LogicException('Property count findings require an exact class declaration subject');
        $metrics = $this->admittedMetrics($context, $subject, $declaration, function (MetricBag $metrics) use ($context, $subject, &$options): iterable {
            $options = $this->getEffectiveOptions($context, $options, $subject);
            yield GateInput::metrics('class-value', $metrics);
            yield GateInput::metrics('exclude-readonly', $metrics, option: $options->excludeReadonly);
            yield GateInput::metrics('exclude-promoted-only', $metrics, option: $options->excludePromotedOnly);
        }, [GateInput::kind('class-coordinate', $subject->toSymbolPath()->getType())], level: SymbolLevel::Class_);
        if ($metrics === null) {
            return null;
        }
        $propertyCount = $metrics->get(MetricName::SIZE_PROPERTY_COUNT);
        $propertyCountValue = (int) $propertyCount;

        /** @var PropertyCountOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($propertyCountValue);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $message = \sprintf(
            'Property count is %d, ' . ThresholdCrossing::of($propertyCountValue, $threshold)->value . ' threshold of %d. Consider splitting the class or using composition',
            $propertyCountValue,
            $threshold,
        );
        $recommendation = \sprintf('Properties: %d (threshold: %d) — too many properties', $propertyCountValue, $threshold);
        $location = new Location($classInfo->file, $classInfo->line);
        $symbolPath = $subject->toSymbolPath();

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $this->getName(),
            code: self::NAME,
            message: $message,
            severity: $severity,
            metricValue: $propertyCountValue,
            recommendation: $recommendation,
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
