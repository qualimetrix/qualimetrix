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
 * Rule that detects God Classes using Lanza & Marinescu criteria.
 *
 * A God Class is overly complex, large, and lacks cohesion.
 * Detection is based on 4 criteria: WMC, LCOM4, TCC, and class LOC.
 * A class is flagged when it matches minCriteria of the evaluable criteria.
 */
#[CliAlias('god-class-wmc-threshold', 'wmcThreshold')]
#[CliAlias('god-class-lcom-threshold', 'lcomThreshold')]
#[CliAlias('god-class-tcc-threshold', 'tccThreshold')]
#[CliAlias('god-class-class-loc-threshold', 'classLocThreshold')]
#[CliAlias('god-class-min-criteria', 'minCriteria')]
#[CliAlias('god-class-min-methods', 'minMethods')]
#[CliAlias('god-class-exclude-readonly', 'excludeReadonly')]
final class GodClassRule extends AbstractRule
{
    public const string NAME = 'design.god-class';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects God Classes (overly complex, large, low cohesion)';
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
            MetricName::STRUCTURE_WMC,
            MetricName::STRUCTURE_LCOM,
            MetricName::COHESION_TCC,
            MetricName::SIZE_CLASS_LOC,
            MetricName::STRUCTURE_METHOD_COUNT,
            MetricName::STRUCTURE_IS_READONLY,
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof GodClassOptions || !$this->options->isEnabled()) {
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
            \assert($effectiveOptions instanceof GodClassOptions);

            // Skip readonly classes if configured
            if ($effectiveOptions->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
                continue;
            }

            // Skip classes with too few methods
            $methodCount = (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0);
            if ($methodCount < $effectiveOptions->minMethods) {
                continue;
            }

            // Evaluate up to 4 criteria
            $evaluableCount = 0;
            $matchedCount = 0;
            $matchedCriteria = [];

            // 1. WMC >= wmcThreshold
            $wmc = $metrics->get(MetricName::STRUCTURE_WMC);
            if ($wmc !== null) {
                $wmcValue = (int) $wmc;
                $evaluableCount++;
                if ($wmcValue >= $effectiveOptions->wmcThreshold) {
                    $matchedCount++;
                    $matchedCriteria[] = \sprintf('high WMC (%d >= %d)', $wmcValue, $effectiveOptions->wmcThreshold);
                }
            }

            // 2. LCOM >= lcomThreshold (vetoed if TCC >= 0.5 — high cohesion overrides LCOM)
            $lcom = $metrics->get(MetricName::STRUCTURE_LCOM);
            $tcc = $metrics->get(MetricName::COHESION_TCC);
            $tccValue = $tcc !== null ? (float) $tcc : null;

            if ($lcom !== null) {
                $lcomValue = (int) $lcom;
                $lcomVetoed = $tccValue !== null && $tccValue >= 0.5;
                if ($lcomVetoed) {
                    // TCC >= 0.5 means the class IS cohesive — don't count LCOM at all
                } else {
                    $evaluableCount++;
                    if ($lcomValue >= $effectiveOptions->lcomThreshold) {
                        $matchedCount++;
                        $matchedCriteria[] = \sprintf('high LCOM (%d >= %d)', $lcomValue, $effectiveOptions->lcomThreshold);
                    }
                }
            }

            // 3. TCC < tccThreshold (inverted — low TCC is bad)
            if ($tccValue !== null) {
                $evaluableCount++;
                if ($tccValue < $effectiveOptions->tccThreshold) {
                    $matchedCount++;
                    $matchedCriteria[] = \sprintf('low TCC (%.2f < %.2f)', $tccValue, $effectiveOptions->tccThreshold);
                }
            }

            // 4. classLoc >= classLocThreshold
            $classLoc = $metrics->get(MetricName::SIZE_CLASS_LOC);
            if ($classLoc !== null) {
                $classLocValue = (int) $classLoc;
                $evaluableCount++;
                if ($classLocValue >= $effectiveOptions->classLocThreshold) {
                    $matchedCount++;
                    $matchedCriteria[] = \sprintf('large size (%d >= %d LOC)', $classLocValue, $effectiveOptions->classLocThreshold);
                }
            }

            // Not enough evaluable criteria
            if ($evaluableCount < $effectiveOptions->minCriteria) {
                continue;
            }

            // Determine severity
            $severity = null;
            if ($matchedCount === $evaluableCount) {
                $severity = Severity::Error;
            } elseif ($matchedCount >= $effectiveOptions->minCriteria) {
                $severity = Severity::Warning;
            }

            if ($severity === null) {
                continue;
            }

            $violations[] = new Violation(
                location: new Location(RelativePath::fromString(PathNormalizer::relativize($classInfo->file)), $classInfo->line),
                symbolPath: $classInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: self::NAME,
                message: \sprintf(
                    'God Class detected (%d/%d criteria): %s',
                    $matchedCount,
                    $evaluableCount,
                    implode(', ', $matchedCriteria),
                ),
                severity: $severity,
                metricValue: $matchedCount,
                recommendation: 'Apply the Single Responsibility Principle. Extract cohesive method groups into separate classes.',
            );
        }

        return $violations;
    }

    /**
     * @return class-string<GodClassOptions>
     */
    public static function getOptionsClass(): string
    {
        return GodClassOptions::class;
    }
}
