<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareName;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * Which layers wrote each computed metric, so a refusal about a resolved
 * definition names them. A definition no layer touched is the built-in
 * defaults'.
 */
final readonly class ComputedMetricAuthorship
{
    /** @param array<string, ResolvedMap|ResolvedBareName> $entries metric name => its merged entry */
    public function __construct(private array $entries = []) {}

    public function entry(string $metricName): ResolvedMap|ResolvedBareName|null
    {
        return $this->entries[$metricName] ?? null;
    }

    public function refuseMetric(string $metricName, string $summary): ConfigurationRefusal
    {
        return $this->entry($metricName)?->refusal($summary) ?? self::defaults($metricName, $summary);
    }

    /**
     * The layer whose `formulas.<level>` or `formula` the level runs; the
     * whole entry when neither was written at that level.
     */
    public function refuseFormula(string $metricName, string $level, string $summary): ConfigurationRefusal
    {
        return $this->formulaWriter($metricName, $level)?->refusal($summary) ?? $this->refuseMetric($metricName, $summary);
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
    public function writersOfFormula(string $metricName, string $level): array
    {
        return ($this->formulaWriter($metricName, $level) ?? $this->entry($metricName))?->contributors() ?? [];
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

    private function formulaWriter(string $metricName, string $level): ?ResolvedValueInterface
    {
        $entry = $this->entry($metricName);
        if (!$entry instanceof ResolvedMap) {
            return null;
        }

        $formulas = $entry->get(ComputedMetricEntryKeys::FORMULAS);

        return ($formulas instanceof ResolvedMap ? $formulas->get($level) : null)
            ?? $entry->get(ComputedMetricEntryKeys::FORMULA);
    }

    /** @param list<string> $below the key path under the metric */
    private static function defaults(string $metricName, string $summary, array $below = []): ConfigurationRefusal
    {
        $path = [ComputedMetricsSection::KEY, $metricName, ...$below];

        return ConfigurationRefusal::at(
            ConfigurationOrigin::of(ConfigurationSource::Defaults),
            RefusedPosition::open($path, $path[\count($path) - 1]),
            $summary,
        );
    }
}
