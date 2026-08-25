<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that checks if classes have too many properties.
 *
 * Too many properties may indicate a God Class that violates the Single Responsibility Principle.
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

    public function getDescription(): string
    {
        return 'Checks if classes have too many properties';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Size;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::STRUCTURE_PROPERTY_COUNT, MetricName::STRUCTURE_IS_READONLY, MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY];
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
     * (line 68) / `$value >= $this->warning` (line 72).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
        ];
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof PropertyCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $finding = $this->findingForClass($classInfo, $context, $this->options);
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
    ): ?Finding {
        $subject = $classInfo->subject ?? throw new LogicException('Property count findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $metrics = $context->metrics->get($subject->toSymbolPath());
        $propertyCountValue = $this->eligiblePropertyCount($metrics, $options);
        if ($propertyCountValue === null) {
            return null;
        }

        /** @var PropertyCountOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($propertyCountValue);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $message = \sprintf(
            'Property count is %d, exceeds threshold of %d. Consider splitting the class or using composition',
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

    private function eligiblePropertyCount(MetricBag $metrics, PropertyCountOptions $options): ?int
    {
        $propertyCount = $metrics->get(MetricName::STRUCTURE_PROPERTY_COUNT);
        if ($propertyCount === null) {
            return null;
        }
        if ($options->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
            return null;
        }
        if ($options->excludePromotedOnly && $metrics->get(MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY) === 1) {
            return null;
        }

        return (int) $propertyCount;
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
