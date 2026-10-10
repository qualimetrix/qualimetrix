<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Reads every possible formula input against the caller's exact definition catalog. */
final readonly class ComputedMetricReach implements ComputedMetricReachInterface
{
    public function __construct(
        private MetricReachCatalogInterface $metricReachCatalog,
        private ComputedMetricExpression $expression,
    ) {}

    public function reachAt(
        string $metricName,
        SymbolLevel $level,
        ComputedMetricDefinitionCatalogInterface $definitions,
    ): MetricReach {
        return $this->resolve($metricName, $level, $definitions, []);
    }

    /** @param list<string> $path */
    private function resolve(
        string $metricName,
        SymbolLevel $level,
        ComputedMetricDefinitionCatalogInterface $definitions,
        array $path,
    ): MetricReach {
        if (\in_array($metricName, $path, true)) {
            throw new LogicException('Circular computed metric reach: ' . implode(' -> ', [...$path, $metricName]));
        }

        $definition = $definitions->find($metricName)
            ?? throw new LogicException(\sprintf('Unknown computed metric "%s".', $metricName));
        if (!$definition->hasLevel($level)) {
            throw new LogicException(\sprintf('Computed metric "%s" does not report at level "%s".', $metricName, $level->value));
        }
        $formula = $definition->getFormulaForLevel($level)
            ?? throw new LogicException(\sprintf('Computed metric "%s" has no formula at level "%s".', $metricName, $level->value));

        $this->expression->parse($formula);
        if (!$this->expression->everyAccessIsALiteralIndex($formula)) {
            throw new LogicException(\sprintf('Computed metric "%s" has a non-literal metric access.', $metricName));
        }

        $reach = MetricReach::Members;
        foreach ($this->expression->keysOf($formula) as $key) {
            $inputReach = $this->inputReach($key, $level, $definitions, [...$path, $metricName]);
            if ($inputReach === MetricReach::Run) {
                $reach = MetricReach::Run;
            }
        }

        return $reach;
    }

    /** @param list<string> $path */
    private function inputReach(
        string $key,
        SymbolLevel $level,
        ComputedMetricDefinitionCatalogInterface $definitions,
        array $path,
    ): MetricReach {
        if (!ComputedMetricExpression::isComputedReference($key)) {
            return $this->metricReachCatalog->metricReach($key);
        }

        $definition = $definitions->find($key)
            ?? throw new LogicException(\sprintf('Unknown computed metric "%s".', $key));

        // A known metric unpublished at this level is always absent, including behind ??.
        if (!$definition->hasLevel($level)) {
            return MetricReach::Members;
        }

        return $this->resolve($key, $level, $definitions, $path);
    }
}
