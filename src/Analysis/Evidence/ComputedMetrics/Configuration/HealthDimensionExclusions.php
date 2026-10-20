<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;

/** Resolved health exclusions and the writers of their rebuilt overall formula. */
final class HealthDimensionExclusions
{
    /**
     * @param array<string, ComputedMetricDefinition> $definitions
     * @param list<array{dimension: string, writer: Provenance}> $disabled
     *
     * @return list<ComputedMetricDefinition>
     */
    public static function apply(
        array $definitions,
        ?ResolvedValueInterface $section,
        array $disabled,
        ComputedMetricAuthorship $authorship,
        HealthFormulaExclusionInterface $excluder,
    ): array {
        $exclusions = [...$disabled, ...self::excludedDimensions($section, $definitions)];
        $result = array_values($definitions);
        if ($exclusions === []) {
            return $result;
        }

        return $excluder->applyExcludeHealth(
            $result,
            array_values(array_unique(array_column($exclusions, 'dimension'))),
            static fn(string $level, string $summary): ConfigurationRefusal => Provenance::refusalOf(
                [
                    ...$authorship->writersOfFormula($definitions[HealthDimension::Overall->value], $level),
                    ...array_column($exclusions, 'writer'),
                ],
                $summary,
            ),
        );
    }

    /**
     * Every `exclude_health` item, judged in the words of the layer that
     * wrote it: a bare name (`typing`) and a full one (`health.typing`) both
     * name a dimension. `health.overall` is not judged.
     *
     * @param array<string, ComputedMetricDefinition> $definitions
     *
     * @throws ConfigurationRefusal
     *
     * @return list<array{dimension: string, writer: Provenance}>
     */
    private static function excludedDimensions(?ResolvedValueInterface $section, array $definitions): array
    {
        if ($section === null) {
            return [];
        }

        if (!$section instanceof ResolvedListInterface) {
            throw new LogicException(\sprintf('%s must be registered with the configuration pipeline.', ExcludeHealthSection::class));
        }

        $known = self::excludableDimensions($definitions);
        $excluded = [];
        foreach ($section->items() as $item) {
            $written = (string) $item->plain();
            $dimension = str_starts_with($written, 'health.') ? $written : 'health.' . $written;

            if ($dimension !== HealthDimension::Overall->value && !\in_array($dimension, $known, true)) {
                $item->refuse(ComputedMetricRefusalWording::unknownExcludedHealthDimension(
                    $written,
                    self::where($item->contributors()[0]),
                    $known,
                ));
            }

            $excluded[] = ['dimension' => $dimension, 'writer' => $item->contributors()[0]];
        }

        return $excluded;
    }

    /**
     * The health dimensions defined after every layer, `health.overall` aside.
     *
     * @param array<string, ComputedMetricDefinition> $definitions
     *
     * @return list<string>
     */
    private static function excludableDimensions(array $definitions): array
    {
        $known = [];
        foreach (array_keys($definitions) as $name) {
            if (str_starts_with($name, 'health.') && $name !== HealthDimension::Overall->value) {
                $known[] = $name;
            }
        }

        return $known;
    }

    /** `"exclude_health[0]" in configuration file "qmx.yaml"`, or `option --exclude-health`. */
    private static function where(Provenance $writer): string
    {
        return $writer->path === null || $writer->path === []
            ? $writer->origin->describe()
            : \sprintf('"%s" in %s', $writer->displayPath(), $writer->origin->describe());
    }
    private function __construct() {}
}
