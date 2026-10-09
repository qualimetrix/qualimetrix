<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareNameInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricAuthorship;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Lays the resolved `computed_metrics` and `exclude_health` sections over the
 * built-in definitions and validates the result (syntax, coverage, circular
 * deps, references).
 *
 * A metric's name is judged by the configuration document, in the layer
 * that wrote it ({@see ComputedMetricsSection}). A name written without a
 * body changes nothing about a metric that exists, and defines a new one with
 * nothing — which the formula validator then refuses for its missing formula,
 * naming the layer that wrote the name.
 */
final class ComputedMetricsConfigResolver
{
    public function __construct(
        private readonly ComputedMetricFormulaValidator $formulaValidator,
        private readonly HealthFormulaExclusionInterface $healthFormulaExcluder,
    ) {}

    /** @return list<ComputedMetricDefinition> */
    public function resolve(ResolvedDocument $document): array
    {
        return $this->resolveWithSources($document)->all();
    }

    /**
     * @throws ConfigurationRefusal
     */
    public function resolveWithSources(ResolvedDocument $document): ResolvedComputedMetricDefinitions
    {
        $definitions = ComputedMetricDefaults::getDefaults();
        $entries = self::entries($document->get(ComputedMetricsSection::KEY));

        // A dimension switched off by `enabled: false` goes through the same
        // renormalization as `exclude_health`, so `health.overall` keeps a
        // weighted sum over what remains instead of scoring it as a neutral 75.
        $exclusions = [];
        foreach ($entries as $name => $entry) {
            $this->applyEntry($name, $entry, $definitions, $exclusions);
        }

        $exclusions = [...$exclusions, ...self::excludedDimensions($document->get(ExcludeHealthSection::KEY), $definitions)];
        $authorship = new ComputedMetricAuthorship($entries);

        $result = array_values($definitions);
        if ($exclusions !== []) {
            $result = $this->healthFormulaExcluder->applyExcludeHealth(
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

        $this->formulaValidator->validate($result, $authorship);

        return new ResolvedComputedMetricDefinitions(
            $result,
            static fn(ComputedMetricDefinition $definition, SymbolLevel $level, string $summary): ConfigurationRefusal => $authorship->refuseFormula($definition, $level->value, $summary),
        );
    }

    /**
     * @return array<string, ResolvedMapInterface|ResolvedBareNameInterface>
     */
    private static function entries(?ResolvedValueInterface $section): array
    {
        if ($section === null) {
            return [];
        }

        if (!$section instanceof ResolvedMapInterface) {
            throw self::undeclared(ComputedMetricsSection::class);
        }

        $entries = [];
        foreach ($section->entries() as $name => $entry) {
            $entries[$name] = $entry instanceof ResolvedMapInterface || $entry instanceof ResolvedBareNameInterface
                ? $entry
                : throw new LogicException('A computed_metrics entry resolves to a map or a bare name.');
        }

        return $entries;
    }

    /**
     * @param array<string, ComputedMetricDefinition> $definitions
     * @param list<array{dimension: string, writer: Provenance}> $exclusions
     *
     * @param-out array<string, ComputedMetricDefinition> $definitions
     * @param-out list<array{dimension: string, writer: Provenance}> $exclusions
     *
     * @throws ConfigurationRefusal
     */
    private function applyEntry(string $name, ResolvedMapInterface|ResolvedBareNameInterface $entry, array &$definitions, array &$exclusions): void
    {
        if ($entry instanceof ResolvedBareNameInterface) {
            if (!isset($definitions[$name])) {
                $definitions[$name] = ComputedMetricOverrideReader::create($name, $entry);
            }

            return;
        }

        $isHealth = str_starts_with($name, 'health.');
        $enabled = $entry->get(ComputedMetricEntryKeys::ENABLED);
        if ($enabled !== null && $enabled->plain() === false) {
            if ($isHealth && $name !== HealthDimension::Overall->value) {
                $exclusions[] = ['dimension' => $name, 'writer' => $enabled->contributors()[0]];

                return;
            }

            unset($definitions[$name]);

            return;
        }

        $definitions[$name] = isset($definitions[$name])
            ? ComputedMetricOverrideReader::merge($definitions[$name], $entry)
            : ComputedMetricOverrideReader::create($name, $entry);
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
            throw self::undeclared(ExcludeHealthSection::class);
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

    /** @param class-string $section */
    private static function undeclared(string $section): LogicException
    {
        return new LogicException(\sprintf('%s must be registered with the configuration pipeline.', $section));
    }

    /** `"exclude_health[0]" in configuration file "qmx.yaml"`, or `option --exclude-health`. */
    private static function where(Provenance $writer): string
    {
        return $writer->path === null || $writer->path === []
            ? $writer->origin->describe()
            : \sprintf('"%s" in %s', $writer->displayPath(), $writer->origin->describe());
    }
}
