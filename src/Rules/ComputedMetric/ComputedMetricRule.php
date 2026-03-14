<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\ComputedMetric;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

final class ComputedMetricRule extends AbstractRule
{
    public const string NAME = 'computed.health';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks computed health metrics against thresholds';
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
        return [];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ComputedMetricRuleOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];
        $profiler = ProfilerHolder::get();

        foreach ($this->options->getDefinitions() as $definition) {
            // Skip definitions without thresholds
            if ($definition->warningThreshold === null && $definition->errorThreshold === null) {
                continue;
            }

            $spanName = 'rule.' . self::NAME . '.' . $definition->name;
            $profiler->start($spanName, 'rule.' . self::NAME);

            foreach ($definition->levels as $level) {
                $this->checkLevel($context, $definition, $level, $violations);
            }

            $profiler->stop($spanName);
        }

        return $violations;
    }

    /**
     * @param list<Violation> $violations
     */
    private function checkLevel(
        AnalysisContext $context,
        ComputedMetricDefinition $definition,
        SymbolType $level,
        array &$violations,
    ): void {
        $symbols = $this->getSymbolsForLevel($context, $level);

        foreach ($symbols as [$symbolPath, $location]) {
            $metrics = $context->metrics->get($symbolPath);
            $value = $metrics->get($definition->name);

            if ($value === null) {
                continue;
            }

            $floatValue = (float) $value;
            $severity = $this->determineSeverity($definition, $floatValue);

            if ($severity === null) {
                continue;
            }

            $threshold = $severity === Severity::Error
                ? $definition->errorThreshold
                : $definition->warningThreshold;

            $operator = $definition->inverted ? 'below' : 'above';

            $violations[] = new Violation(
                location: $location,
                symbolPath: $symbolPath,
                ruleName: $this->getName(),
                violationCode: $definition->name,
                message: \sprintf(
                    '%s: %s = %.1f (%s threshold: %s %.1f)',
                    $symbolPath->toString(),
                    $definition->name,
                    $floatValue,
                    $severity->value,
                    $operator,
                    $threshold,
                ),
                severity: $severity,
                metricValue: round($floatValue, 1),
            );
        }
    }

    /**
     * For inverted metrics (higher = better): below threshold = violation.
     * For normal metrics (higher = worse): above threshold = violation.
     */
    private function determineSeverity(
        ComputedMetricDefinition $definition,
        float $value,
    ): ?Severity {
        if ($definition->inverted) {
            // Higher is better — below threshold = bad
            if ($definition->errorThreshold !== null && $value < $definition->errorThreshold) {
                return Severity::Error;
            }
            if ($definition->warningThreshold !== null && $value < $definition->warningThreshold) {
                return Severity::Warning;
            }
        } else {
            // Higher is worse — above threshold = bad
            if ($definition->errorThreshold !== null && $value > $definition->errorThreshold) {
                return Severity::Error;
            }
            if ($definition->warningThreshold !== null && $value > $definition->warningThreshold) {
                return Severity::Warning;
            }
        }

        return null;
    }

    /**
     * @return list<array{SymbolPath, Location}>
     */
    private function getSymbolsForLevel(AnalysisContext $context, SymbolType $level): array
    {
        return match ($level) {
            SymbolType::Project => [[SymbolPath::forProject(), Location::none()]],
            SymbolType::Namespace_ => array_map(
                static fn(string $ns) => [SymbolPath::forNamespace($ns), Location::none()],
                $context->metrics->getNamespaces(),
            ),
            SymbolType::Class_ => array_map(
                static fn($info) => [$info->symbolPath, new Location($info->file, $info->line)],
                iterator_to_array($context->metrics->all(SymbolType::Class_), false),
            ),
            default => [],
        };
    }

    /**
     * @return class-string<ComputedMetricRuleOptions>
     */
    public static function getOptionsClass(): string
    {
        return ComputedMetricRuleOptions::class;
    }
}
