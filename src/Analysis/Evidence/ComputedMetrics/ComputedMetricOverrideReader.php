<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareName;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedList;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * One merged `computed_metrics` entry, read into a definition.
 *
 * The document engine has already judged every key and the form of every
 * value, judged the metric's name, and merged the layers key by key; what is
 * left here is what a key's meaning adds — which words are levels — and
 * laying the entry over the definition it overrides. Each refusal names the
 * layers that wrote the value it is about.
 */
final class ComputedMetricOverrideReader
{
    /**
     * Lays an entry over a built-in definition.
     *
     * @throws ConfigurationRefusal
     */
    public static function merge(ComputedMetricDefinition $base, ResolvedMap $entry): ComputedMetricDefinition
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
    public static function create(string $name, ResolvedMap|ResolvedBareName $entry): ComputedMetricDefinition
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
    private static function formulas(ResolvedMap|ResolvedBareName $entry, array $defaults): array
    {
        $formulas = $defaults;

        $formula = self::string($entry, ComputedMetricEntryKeys::FORMULA);
        if ($formula !== null) {
            foreach (ComputedMetricEntryKeys::REPORTING_LEVELS as $level) {
                $formulas[$level->value] = $formula;
            }
        }

        $perLevel = self::field($entry, ComputedMetricEntryKeys::FORMULAS);
        foreach ($perLevel instanceof ResolvedMap ? $perLevel->plain() : [] as $level => $levelFormula) {
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
    private static function levels(ResolvedMap|ResolvedBareName $entry, array $defaults, string $name): array
    {
        $written = self::field($entry, ComputedMetricEntryKeys::LEVELS);
        if (!$written instanceof ResolvedList) {
            return $defaults;
        }

        $levels = [];
        foreach ($written->items() as $item) {
            $levels[] = self::mapLevel((string) $item->plain(), $item);
        }

        if (ComputedMetricDefinition::hasDuplicateLevel($levels)) {
            throw $written->refusal(ComputedMetricRefusalWording::duplicateLevel($name));
        }

        return $levels;
    }

    /**
     * `callable` and `file` are real level words this capability does not
     * report at — a different mistake from a word that is no level at all.
     *
     * @throws ConfigurationRefusal
     */
    private static function mapLevel(string $word, ResolvedValueInterface $item): SymbolLevel
    {
        $level = SymbolLevel::tryFrom($word);

        if ($level === null) {
            throw $item->refusal(ComputedMetricRefusalWording::levelWordNotALevelAtAll($word));
        }

        if (!\in_array($level, ComputedMetricEntryKeys::REPORTING_LEVELS, true)) {
            throw $item->refusal(ComputedMetricRefusalWording::levelWordNotAReportingLevel($word, self::reportingLevelWords()));
        }

        return $level;
    }

    /** @return list<string> */
    private static function reportingLevelWords(): array
    {
        return array_map(
            static fn(SymbolLevel $level): string => $level->value,
            ComputedMetricEntryKeys::REPORTING_LEVELS,
        );
    }

    private static function field(ResolvedMap|ResolvedBareName $entry, string $key): ?ResolvedValueInterface
    {
        return $entry instanceof ResolvedMap ? $entry->get($key) : null;
    }

    private static function string(ResolvedMap|ResolvedBareName $entry, string $key): ?string
    {
        $value = self::field($entry, $key)?->plain();

        return \is_string($value) ? $value : null;
    }

    private static function bool(ResolvedMap|ResolvedBareName $entry, string $key): ?bool
    {
        $value = self::field($entry, $key)?->plain();

        return \is_bool($value) ? $value : null;
    }

    private static function number(ResolvedMap|ResolvedBareName $entry, string $key): ?float
    {
        $value = self::field($entry, $key)?->plain();

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }
}
