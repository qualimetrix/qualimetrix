<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\Attribute\CliAlias;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Util\PathNormalizer;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that detects Data Classes — classes with high public surface but low complexity.
 *
 * A Data Class has many public accessors (high WOC) but simple logic (low WMC),
 * suggesting it only holds data without encapsulating behavior.
 * Pure DTOs (readonly, promoted-properties-only, or marked as data class) are excluded.
 */
#[CliAlias('data-class-woc-threshold', 'wocThreshold')]
#[CliAlias('data-class-wmc-threshold', 'wmcThreshold')]
#[CliAlias('data-class-min-methods', 'minMethods')]
#[CliAlias('data-class-exclude-readonly', 'excludeReadonly')]
#[CliAlias('data-class-exclude-promoted-only', 'excludePromotedOnly')]
#[CliAlias('data-class-exclude-exceptions', 'excludeExceptions')]
final class DataClassRule extends AbstractRule
{
    public const string NAME = 'design.data-class';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects classes with high public surface but low complexity (Data Classes)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Design;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [
            MetricName::STRUCTURE_WOC,
            MetricName::STRUCTURE_WMC,
            MetricName::STRUCTURE_METHOD_COUNT,
            MetricName::STRUCTURE_PROPERTY_COUNT,
            MetricName::STRUCTURE_IS_READONLY,
            MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY,
            MetricName::STRUCTURE_IS_DATA_CLASS,
            MetricName::STRUCTURE_IS_ABSTRACT,
            MetricName::STRUCTURE_IS_INTERFACE,
            MetricName::STRUCTURE_IS_EXCEPTION,
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof DataClassOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            // Apply @qmx-threshold overrides for this class
            $effectiveOptions = $this->getEffectiveOptions(
                $context,
                $this->options,
                $classInfo->file,
                $classInfo->line ?? 1,
            );
            \assert($effectiveOptions instanceof DataClassOptions);

            // Interfaces are contracts, not data classes — 100% WOC by definition
            if ($metrics->get(MetricName::STRUCTURE_IS_INTERFACE) === 1) {
                continue;
            }

            // Abstract classes are contracts, not data classes
            if ($metrics->get(MetricName::STRUCTURE_IS_ABSTRACT) === 1) {
                continue;
            }

            // Classes with zero properties cannot be data classes by definition
            $propertyCount = (int) ($metrics->get(MetricName::STRUCTURE_PROPERTY_COUNT) ?? 0);
            if ($propertyCount === 0) {
                continue;
            }

            // Exception classes are DTOs by design — they hold error context, not behavior
            if ($effectiveOptions->excludeExceptions && $metrics->get(MetricName::STRUCTURE_IS_EXCEPTION) === 1) {
                continue;
            }

            // Skip readonly classes if configured
            if ($effectiveOptions->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
                continue;
            }

            // Skip promoted-properties-only classes if configured
            if ($effectiveOptions->excludePromotedOnly && $metrics->get(MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY) === 1) {
                continue;
            }

            // Skip classes with too few methods
            $methodCount = (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0);
            if ($methodCount < $effectiveOptions->minMethods) {
                continue;
            }

            // Skip intentional data classes (pure DTOs)
            if ($metrics->get(MetricName::STRUCTURE_IS_DATA_CLASS) === 1) {
                continue;
            }

            $woc = $metrics->get(MetricName::STRUCTURE_WOC);
            if ($woc === null) {
                continue;
            }

            $wocValue = (int) $woc;
            $wmcValue = (int) ($metrics->get(MetricName::STRUCTURE_WMC) ?? 0);

            // Data Class: high WOC (public surface) + low WMC (complexity)
            if ($wocValue >= $effectiveOptions->wocThreshold && $wmcValue <= $effectiveOptions->wmcThreshold) {
                $violations[] = new Violation(
                    location: new Location(RelativePath::fromString(PathNormalizer::relativize($classInfo->file)), $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'Data Class detected: high public surface (WOC=%d%%, threshold %d%%) with low complexity (WMC=%d, threshold %d). Consider encapsulating behavior or using a DTO pattern',
                        $wocValue,
                        $effectiveOptions->wocThreshold,
                        $wmcValue,
                        $effectiveOptions->wmcThreshold,
                    ),
                    severity: Severity::Warning,
                    metricValue: $wocValue,
                    recommendation: 'Add behavior methods that operate on the data, or confirm this is intentionally a DTO.',
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<DataClassOptions>
     */
    public static function getOptionsClass(): string
    {
        return DataClassOptions::class;
    }
}
