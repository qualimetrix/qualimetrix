<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Maintainability;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks Maintainability Index at method level.
 *
 * MI thresholds (lower is worse):
 * - MI >= 40: good (no violation)
 * - MI 20-39: warning
 * - MI < 20: error
 */
final class MaintainabilityRule extends AbstractRule
{
    public const string NAME = 'maintainability.index';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Maintainability Index (lower values indicate harder to maintain code)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Maintainability;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::MAINTAINABILITY_MI, MetricName::HALSTEAD_METHOD_LOC];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof MaintainabilityOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $methodInfo) {
            // Skip test files if configured
            if ($this->options->excludeTests && $this->isTestFile($methodInfo->file)) {
                continue;
            }

            $metrics = $context->metrics->get($methodInfo->symbolPath);

            // Skip methods with too few LOC
            $methodLoc = (int) ($metrics->get(MetricName::HALSTEAD_METHOD_LOC) ?? 0);
            if ($methodLoc < $this->options->minLoc) {
                continue;
            }

            $mi = $metrics->get(MetricName::MAINTAINABILITY_MI);

            if ($mi === null) {
                continue;
            }

            $miValue = (float) $mi;
            /** @var MaintainabilityOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $methodInfo->file, $methodInfo->line ?? 1);
            $severity = $effectiveOptions->getSeverity($miValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $effectiveOptions->error
                    : $effectiveOptions->warning;

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    symbolPath: $methodInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'Maintainability Index is %.1f, below threshold of %.1f. Reduce complexity and size to improve maintainability',
                        $miValue,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: round($miValue, 1),
                    recommendation: \sprintf('MI: %.1f (threshold: %.1f) — code is hard to change safely', $miValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<MaintainabilityOptions>
     */
    public static function getOptionsClass(): string
    {
        return MaintainabilityOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'mi-warning' => 'warning',
            'mi-error' => 'error',
            'mi-exclude-tests' => 'excludeTests',
            'mi-min-loc' => 'minLoc',
        ];
    }

    private function isTestFile(string $file): bool
    {
        return str_ends_with($file, 'Test.php')
            || str_contains($file, '/tests/')
            || str_contains($file, '/Tests/');
    }
}
