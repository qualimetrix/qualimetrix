<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KindIn;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Observation\WorseDirection;
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
            self::NAME => ChannelDeclaration::judging(
                WorseDirection::Higher,
                JudgedMetrics::of(MetricName::SIZE_PROPERTY_COUNT),
                SymbolLevel::Class_,
            )->withGates(
                new PopulationGate('class-coordinate', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KindIn('class-coordinate', [SymbolType::Class_]), 'The subject is outside the class coordinate.'),
                new PopulationGate('class-value', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new KeyPresent('class-value', [MetricName::SIZE_PROPERTY_COUNT]), 'The class metric was not published.'),
                new PopulationGate('exclude-readonly', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new FlagExcludes('exclude-readonly', MetricName::DESIGN_IS_READONLY, 1, true), 'The configured class exclusion applies.'),
                new PopulationGate('exclude-promoted-only', new FindingChannel(self::NAME), SymbolLevel::Class_, 'declaration', new FlagExcludes('exclude-promoted-only', MetricName::DESIGN_IS_PROMOTED_PROPERTIES_ONLY, 1, true), 'The configured class exclusion applies.'),
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
        $metrics = null;
        if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Class_, PopulationIdentity::subject($subject), $declaration, (function () use ($context, $subject, &$metrics, &$options): iterable {
            yield GateInput::kind('class-coordinate', $subject->toSymbolPath()->getType());
            $metrics = $context->metrics->getSubject($subject);
            $options = $this->getEffectiveOptions($context, $options, $subject);
            yield GateInput::metrics('class-value', $metrics);
            yield GateInput::metrics('exclude-readonly', $metrics, option: $options->excludeReadonly);
            yield GateInput::metrics('exclude-promoted-only', $metrics, option: $options->excludePromotedOnly);
        })())) {
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
