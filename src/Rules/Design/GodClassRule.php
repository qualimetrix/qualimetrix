<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
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

            // Skip readonly classes if configured
            if ($this->options->excludeReadonly && $metrics->get(MetricName::STRUCTURE_IS_READONLY) === 1) {
                continue;
            }

            // Skip classes with too few methods
            $methodCount = (int) ($metrics->get(MetricName::STRUCTURE_METHOD_COUNT) ?? 0);
            if ($methodCount < $this->options->minMethods) {
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
                if ($wmcValue >= $this->options->wmcThreshold) {
                    $matchedCount++;
                    $matchedCriteria[] = \sprintf('high WMC (%d >= %d)', $wmcValue, $this->options->wmcThreshold);
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
                    if ($lcomValue >= $this->options->lcomThreshold) {
                        $matchedCount++;
                        $matchedCriteria[] = \sprintf('high LCOM (%d >= %d)', $lcomValue, $this->options->lcomThreshold);
                    }
                }
            }

            // 3. TCC < tccThreshold (inverted — low TCC is bad)
            if ($tccValue !== null) {
                $evaluableCount++;
                if ($tccValue < $this->options->tccThreshold) {
                    $matchedCount++;
                    $matchedCriteria[] = \sprintf('low TCC (%.2f < %.2f)', $tccValue, $this->options->tccThreshold);
                }
            }

            // 4. classLoc >= classLocThreshold
            $classLoc = $metrics->get(MetricName::SIZE_CLASS_LOC);
            if ($classLoc !== null) {
                $classLocValue = (int) $classLoc;
                $evaluableCount++;
                if ($classLocValue >= $this->options->classLocThreshold) {
                    $matchedCount++;
                    $matchedCriteria[] = \sprintf('large size (%d >= %d LOC)', $classLocValue, $this->options->classLocThreshold);
                }
            }

            // Not enough evaluable criteria
            if ($evaluableCount < $this->options->minCriteria) {
                continue;
            }

            // Determine severity
            $severity = null;
            if ($matchedCount === $evaluableCount) {
                $severity = Severity::Error;
            } elseif ($matchedCount >= $this->options->minCriteria) {
                $severity = Severity::Warning;
            }

            if ($severity === null) {
                continue;
            }

            $violations[] = new Violation(
                location: new Location($classInfo->file, $classInfo->line),
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

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'god-class-wmc-threshold' => 'wmcThreshold',
            'god-class-lcom-threshold' => 'lcomThreshold',
            'god-class-tcc-threshold' => 'tccThreshold',
            'god-class-class-loc-threshold' => 'classLocThreshold',
            'god-class-min-criteria' => 'minCriteria',
            'god-class-min-methods' => 'minMethods',
            'god-class-exclude-readonly' => 'excludeReadonly',
        ];
    }
}
