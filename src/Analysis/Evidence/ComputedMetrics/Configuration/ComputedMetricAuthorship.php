<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Which layers wrote each computed metric, so a refusal about a resolved
 * definition names them. A definition no layer touched is the built-in
 * defaults'.
 *
 * @qmx-threshold coupling.instability warning=0.82 -- Ca=2, Ce=9: it reads the resolved
 * document (three types) and builds the refusal (two types) for its two callers, so it
 * depends outward by construction.
 */
final readonly class ComputedMetricAuthorship
{
    /** @param array<string, ResolvedValueInterface> $entries metric name => its merged entry */
    public function __construct(private array $entries = []) {}

    public function refuseMetric(string $metricName, string $summary): ConfigurationRefusal
    {
        $entry = $this->entry($metricName);

        return $entry === null ? self::defaults($metricName, $summary) : Provenance::refusalOf($entry->contributors(), $summary);
    }

    /**
     * The layer whose `formulas.<level>` or `formula` the level runs; the
     * defaults when neither wrote the formula selected by the definition.
     */
    public function refuseFormula(ComputedMetricDefinition $definition, string $level, string $summary): ConfigurationRefusal
    {
        $writer = $this->formulaWriter($definition, $level);

        return $writer !== null ? Provenance::refusalOf($writer->contributors(), $summary) : self::defaults($definition->name, $summary, [
            ComputedMetricEntryKeys::FORMULAS,
            $definition->formulaLevelFor(SymbolLevel::from($level)) ?? $level,
        ]);
    }

    /**
     * A level the metric reports at with no formula: the layers that wrote the
     * metric, at the key the author left out.
     */
    public function refuseMissingFormula(string $metricName, string $level, string $summary): ConfigurationRefusal
    {
        $writers = $this->entry($metricName)?->contributors() ?? [];
        if ($writers === []) {
            return self::defaults($metricName, $summary, [ComputedMetricEntryKeys::FORMULAS, $level]);
        }

        $last = $writers[\count($writers) - 1];

        return Provenance::refusalOf(
            $writers,
            $summary,
            $last->path === null ? null : RefusedPosition::open([...$last->path, ComputedMetricEntryKeys::FORMULAS, $level], $level),
        );
    }

    /**
     * The layers that wrote the formula one level runs, as {@see refuseFormula()}
     * attributes it; empty for a built-in formula.
     *
     * @return list<Provenance>
     */
    public function writersOfFormula(ComputedMetricDefinition $definition, string $level): array
    {
        return $this->formulaWriter($definition, $level)?->contributors() ?? [];
    }

    /**
     * A relation between metrics: every layer that wrote any of them.
     *
     * @param list<string> $metricNames
     */
    public function refuseAcross(array $metricNames, string $summary): ConfigurationRefusal
    {
        $writers = [];
        foreach (array_unique($metricNames) as $metricName) {
            $writers = [...$writers, ...($this->entry($metricName)?->contributors() ?? [])];
        }

        return $writers === [] ? self::defaults($metricNames[0] ?? '', $summary) : Provenance::refusalOf($writers, $summary);
    }

    private function entry(string $metricName): ?ResolvedValueInterface
    {
        return $this->entries[$metricName] ?? null;
    }

    private function formulaWriter(ComputedMetricDefinition $definition, string $level): ?ResolvedValueInterface
    {
        $entry = $this->entry($definition->name);
        if (!$entry instanceof ResolvedMapInterface) {
            return null;
        }

        $selectedLevel = $definition->formulaLevelFor(SymbolLevel::from($level));
        if ($selectedLevel === null) {
            return null;
        }

        $formulas = $entry->get(ComputedMetricEntryKeys::FORMULAS);

        return ($formulas instanceof ResolvedMapInterface ? $formulas->get($selectedLevel) : null)
            ?? $entry->get(ComputedMetricEntryKeys::FORMULA);
    }

    /** @param list<string> $below the key path under the metric */
    private static function defaults(string $metricName, string $summary, array $below = []): ConfigurationRefusal
    {
        return ConfigurationRefusal::atDefaultsKey(ComputedMetricsSection::position($metricName, ...$below), $summary);
    }
}
