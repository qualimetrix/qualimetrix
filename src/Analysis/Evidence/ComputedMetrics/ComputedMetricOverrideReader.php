<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareNameInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricValueForm;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * One merged `computed_metrics` entry, read into a definition.
 *
 * The document engine has already judged every key and the form of every
 * value, including the level vocabulary and duplicates, judged the metric's
 * name, and merged the layers key by key. This reader lays the entry over
 * the definition it overrides. Each refusal names the
 * layers that wrote the value it is about.
 */
final class ComputedMetricOverrideReader
{
    /**
     * Lays an entry over a built-in definition.
     *
     * @throws ConfigurationRefusal
     */
    public static function merge(ComputedMetricDefinition $base, ResolvedMapInterface $entry): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: $base->name,
            formulas: self::formulas($entry, $base->formulas),
            description: self::string($entry, ComputedMetricEntryKeys::DESCRIPTION) ?? $base->description,
            levels: self::levels($entry, $base->levels, $base->name),
            inverted: self::bool($entry, ComputedMetricEntryKeys::INVERTED) ?? $base->inverted,
            warningThreshold: self::number($entry, ComputedMetricEntryKeys::WARNING) ?? $base->warningThreshold,
            errorThreshold: self::number($entry, ComputedMetricEntryKeys::ERROR) ?? $base->errorThreshold,
        );
    }

    /**
     * Creates a user-defined metric: no formula, no description, not
     * inverted, and namespace plus project unless the entry says otherwise.
     * A bare name says otherwise about nothing.
     *
     * @throws ConfigurationRefusal
     */
    public static function create(string $name, ResolvedMapInterface|ResolvedBareNameInterface $entry): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: $name,
            formulas: self::formulas($entry, []),
            description: self::string($entry, ComputedMetricEntryKeys::DESCRIPTION) ?? '',
            levels: self::levels($entry, [SymbolLevel::Namespace_, SymbolLevel::Project], $name),
            inverted: self::bool($entry, ComputedMetricEntryKeys::INVERTED) ?? false,
            warningThreshold: self::number($entry, ComputedMetricEntryKeys::WARNING),
            errorThreshold: self::number($entry, ComputedMetricEntryKeys::ERROR),
        );
    }

    /**
     * `formula` writes every reporting level, then each `formulas.<level>`
     * refines its own level — whichever layers wrote the two.
     *
     * @param array<string, string> $defaults
     *
     * @return array<string, string>
     */
    private static function formulas(ResolvedMapInterface|ResolvedBareNameInterface $entry, array $defaults): array
    {
        $formulas = $defaults;

        $formula = self::string($entry, ComputedMetricEntryKeys::FORMULA);
        if ($formula !== null) {
            foreach (ComputedMetricEntryKeys::REPORTING_LEVELS as $level) {
                $formulas[$level->value] = $formula;
            }
        }

        $perLevel = self::field($entry, ComputedMetricEntryKeys::FORMULAS);
        foreach ($perLevel instanceof ResolvedMapInterface ? $perLevel->plain() : [] as $level => $levelFormula) {
            $formulas[$level] = (string) $levelFormula;
        }

        return $formulas;
    }

    /**
     * @param list<SymbolLevel> $defaults
     *
     * @throws ConfigurationRefusal
     *
     * @return list<SymbolLevel>
     */
    private static function levels(ResolvedMapInterface|ResolvedBareNameInterface $entry, array $defaults, string $name): array
    {
        $written = self::field($entry, ComputedMetricEntryKeys::LEVELS);
        if (!$written instanceof ResolvedListInterface) {
            return $defaults;
        }

        return ComputedMetricValueForm::levels($written, $name, ComputedMetricEntryKeys::REPORTING_LEVELS);
    }

    private static function field(ResolvedMapInterface|ResolvedBareNameInterface $entry, string $key): ?ResolvedValueInterface
    {
        return $entry instanceof ResolvedMapInterface ? $entry->get($key) : null;
    }

    private static function string(ResolvedMapInterface|ResolvedBareNameInterface $entry, string $key): ?string
    {
        $value = self::field($entry, $key)?->plain();

        return \is_string($value) ? $value : null;
    }

    private static function bool(ResolvedMapInterface|ResolvedBareNameInterface $entry, string $key): ?bool
    {
        $value = self::field($entry, $key)?->plain();

        return \is_bool($value) ? $value : null;
    }

    private static function number(ResolvedMapInterface|ResolvedBareNameInterface $entry, string $key): ?float
    {
        $value = self::field($entry, $key)?->plain();

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }
}
