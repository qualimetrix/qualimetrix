<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
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
    /** @param array<string, ResolvedMap> $entries metric name => its merged entry */
    public function __construct(private array $entries = []) {}

    public function entry(string $metricName): ?ResolvedMap
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
        $formulas = $entry?->get(ComputedMetricEntryKeys::FORMULAS);

        return ($formulas instanceof ResolvedMap ? $formulas->get($level) : null)
            ?? $entry?->get(ComputedMetricEntryKeys::FORMULA);
    }

    private static function defaults(string $metricName, string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::at(
            ConfigurationOrigin::of(ConfigurationSource::Defaults),
            RefusedPosition::open([ComputedMetricsSection::KEY, $metricName], $metricName),
            $summary,
        );
    }
}
