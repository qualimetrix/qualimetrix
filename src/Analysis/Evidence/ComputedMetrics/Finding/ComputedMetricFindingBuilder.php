<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

final class ComputedMetricFindingBuilder
{
    public function build(
        ComputedMetricDefinition $definition,
        float $value,
        MetricSubject $subject,
        SymbolPath $symbolPath,
        Location $location,
        string $ruleName,
    ): ?Finding {
        $severity = $this->severity($definition, $value);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $definition->errorThreshold : $definition->warningThreshold;
        \assert($threshold !== null);
        $operator = $definition->inverted ? 'below' : 'above';

        return new Finding(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            code: $definition->name,
            message: \sprintf('%s: %s = %.1f (%s threshold: %s %.1f)', $symbolPath->toString(), $definition->name, $value, $severity->value, $operator, $threshold),
            severity: $severity,
            metricValue: round($value, 1),
            recommendation: $this->recommendation($definition->name, $value, $threshold),
            threshold: $threshold,
        );
    }

    private function severity(ComputedMetricDefinition $definition, float $value): ?Severity
    {
        if ($definition->inverted) {
            if ($definition->errorThreshold !== null && $value < $definition->errorThreshold) {
                return Severity::Error;
            }
            if ($definition->warningThreshold !== null && $value < $definition->warningThreshold) {
                return Severity::Warning;
            }
        } else {
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
     * The metric's leaf, read as words.
     *
     * A separator inside the leaf is a word break, not a character to print:
     * `computed.branch-load` reads "Branch load". It used to print the leaf
     * verbatim, so the separator the name happened to use leaked into a
     * sentence — and Ш5e3, which changed that separator from `_` to `-`, would
     * have moved a published recommendation without renaming anything a reader
     * addresses.
     */
    private static function title(string $dimensionName): string
    {
        $lastDot = strrpos($dimensionName, '.');
        $leaf = $lastDot !== false ? substr($dimensionName, $lastDot + 1) : $dimensionName;

        return ucfirst(str_replace(['-', '_'], ' ', $leaf));
    }

    private function recommendation(string $dimensionName, float $value, float $threshold): string
    {
        $header = \sprintf('%s health: %.1f (threshold: %.1f)', self::title($dimensionName), $value, $threshold);
        $advice = match (true) {
            str_contains($dimensionName, 'complexity') => 'Reduce complexity by extracting methods, simplifying conditional logic, and breaking large classes into focused components.',
            str_contains($dimensionName, 'cohesion') => 'Improve class cohesion by grouping related methods and fields; consider splitting classes that serve multiple unrelated responsibilities.',
            str_contains($dimensionName, 'coupling') => 'Reduce coupling by applying dependency inversion, introducing interfaces, and limiting the number of direct dependencies.',
            str_contains($dimensionName, 'typing') => 'Add type declarations to parameters, return types, and properties to improve type safety and IDE support.',
            str_contains($dimensionName, 'design') => 'Improve design by reducing inheritance depth, limiting the number of subclasses, and preferring composition over inheritance.',
            str_contains($dimensionName, 'maintainability') => 'Improve maintainability by reducing method length, lowering cyclomatic complexity, and adding documentation.',
            default => 'Review the metric value and refactor the affected code to bring it within acceptable thresholds.',
        };

        return $header . ' — ' . $advice;
    }
}
