<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricShapeRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * One entry of the `computed_metrics` YAML section, read into a definition.
 *
 * What belongs here is the vocabulary of that document — which keys mean a
 * formula, which mean a threshold, how a level is spelled, and which names a
 * metric may not have — as opposed to what {@see ComputedMetricsConfigResolver}
 * does with the entries once read: collecting defaults, folding in
 * `enabled: false`, renormalizing health weights and validating the whole set.
 * The two answer to different changes: a new YAML key is a change here, a
 * change to the exclusion pipeline is a change there.
 *
 * Every operation is static because reading a document entry needs no state
 * and no collaborator; the resolver stays constructible exactly as its callers
 * already build it.
 *
 * Every leaf-value form this reader checks is checked here, not by the key
 * traversal {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeyRecognition}
 * that runs before it: the traversal answers for key names and the shape of
 * the containers it walks, this reader for what a caller — the traversal's
 * caller included — actually does with a value, so `merge()`/`create()` stay
 * safe no matter what already ran.
 */
final class ComputedMetricOverrideReader
{
    /**
     * Merges user overrides into an existing definition.
     *
     * @param array<string, mixed> $overrides
     *
     * @throws ConfigurationRefusal
     */
    public static function merge(ComputedMetricDefinition $base, array $overrides): ComputedMetricDefinition
    {
        $name = $base->name;
        $thresholds = self::thresholds($overrides, $base->warningThreshold, $base->errorThreshold, $name);
        $levels = self::levels($overrides, $base->levels, $name);

        self::refuseDuplicateLevel($levels, $name);

        return new ComputedMetricDefinition(
            name: $name,
            formulas: self::formulas($overrides, $base->formulas, $name),
            description: self::description($overrides, $base->description, $name),
            levels: $levels,
            inverted: self::inverted($overrides, $name) ?? $base->inverted,
            warningThreshold: $thresholds['warningThreshold'],
            errorThreshold: $thresholds['errorThreshold'],
        );
    }

    /**
     * Creates a new user-defined computed metric definition.
     *
     * An entry with no defaults behind it is read by the same operations, with
     * the defaults a user-defined metric has instead of a base definition: no
     * formula, no description, not inverted, and namespace plus project.
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationRefusal
     */
    public static function create(string $name, array $config): ComputedMetricDefinition
    {
        self::refuseInvalidNameGrammar($name);
        self::assertNameDoesNotEndInALevel($name);

        $thresholds = self::thresholds($config, null, null, $name);
        $levels = self::levels($config, [SymbolLevel::Namespace_, SymbolLevel::Project], $name);

        self::refuseDuplicateLevel($levels, $name);

        return new ComputedMetricDefinition(
            name: $name,
            formulas: self::formulas($config, [], $name),
            description: self::description($config, '', $name),
            levels: $levels,
            inverted: self::inverted($config, $name) ?? false,
            warningThreshold: $thresholds['warningThreshold'],
            errorThreshold: $thresholds['errorThreshold'],
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $defaults
     *
     * @throws ConfigurationRefusal
     *
     * @return array<string, string>
     */
    private static function formulas(array $config, array $defaults, string $name): array
    {
        $formulas = $defaults;

        // 'formula' (singular) is shorthand — overrides ALL levels with one formula.
        // This replaces any existing per-level formulas (including specialized ones
        // like health.coupling's project formula). If the user wants to override
        // only specific levels, they should use 'formulas' (plural) instead.
        if (isset($config['formula'])) {
            if (!\is_string($config['formula'])) {
                throw self::leafRefusal($name, 'formula', ComputedMetricShapeRefusalWording::mustBeAString($name, 'formula', $config['formula']));
            }

            foreach (ComputedMetricEntryKeys::REPORTING_LEVELS as $level) {
                $formulas[$level->value] = $config['formula'];
            }
        }

        // 'formulas' (plural) per-level — takes precedence. The container's
        // shape and its key names are the traversal's business
        // (ComputedMetricEntryKeyRecognition); by the time this runs, a
        // non-map or an unknown key has already refused. Each value's shape
        // is this reader's own business, same as every other leaf value.
        if (!isset($config['formulas']) || !\is_array($config['formulas'])) {
            return $formulas;
        }

        foreach ($config['formulas'] as $levelKey => $formula) {
            if (!\is_string($formula)) {
                throw ConfigurationRefusal::atResolvedKey(
                    RefusedPosition::open(
                        [...ComputedMetricEntryKeys::nameSegments($name), 'formulas', (string) $levelKey],
                        (string) $levelKey,
                    ),
                    ComputedMetricShapeRefusalWording::formulaValueMustBeAString($name, (string) $levelKey, $formula),
                );
            }

            $formulas[$levelKey] = $formula;
        }

        return $formulas;
    }

    /**
     * `levels:` must be a list of level words. The check sits here rather
     * than in the traversal because the traversal owns key names and
     * container shapes it walks, not this leaf value's shape — and because a
     * reader that stays safe on its own is what keeps `merge()`/`create()`
     * safe for every caller, this and any future one.
     *
     * @param array<string, mixed> $config
     * @param list<SymbolLevel> $defaults
     *
     * @throws ConfigurationRefusal
     *
     * @return list<SymbolLevel>
     */
    private static function levels(array $config, array $defaults, string $name): array
    {
        if (!isset($config['levels'])) {
            return $defaults;
        }

        if (!\is_array($config['levels']) || !array_is_list($config['levels'])) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), 'levels'], 'levels'),
                ComputedMetricShapeRefusalWording::levelListNotAList($name, $config['levels']),
            );
        }

        return array_values(array_map(
            static fn(mixed $level): SymbolLevel => self::mapLevel($level, $name),
            $config['levels'],
        ));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationRefusal
     */
    private static function description(array $config, string $default, string $name): string
    {
        if (!isset($config['description'])) {
            return $default;
        }

        if (!\is_string($config['description'])) {
            throw self::leafRefusal(
                $name,
                'description',
                ComputedMetricShapeRefusalWording::mustBeAString($name, 'description', $config['description']),
            );
        }

        return $config['description'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationRefusal
     *
     * @return ?bool what the entry says, or `null` when it does not say — the
     *               caller alone knows what an unstated `inverted` falls back to
     */
    private static function inverted(array $config, string $name): ?bool
    {
        if (!isset($config['inverted'])) {
            return null;
        }

        if (!\is_bool($config['inverted'])) {
            throw self::leafRefusal(
                $name,
                'inverted',
                ComputedMetricShapeRefusalWording::mustBeABoolean($name, 'inverted', $config['inverted']),
            );
        }

        return $config['inverted'];
    }

    /**
     * A channel's level is a coordinate read off the finding's subject, not a
     * word inside the channel name ({@see FindingChannel}). A user-defined
     * metric name ending in a level word would put the pair back into the
     * name, so it is refused at the same point the reserved `health.*` prefix
     * is refused by the resolver — the moment the name is read from
     * configuration, before it can become a channel.
     *
     * @throws ConfigurationRefusal
     */
    private static function assertNameDoesNotEndInALevel(string $name): void
    {
        $lastDot = strrpos($name, '.');
        $lastSegment = $lastDot === false ? $name : substr($name, $lastDot + 1);

        if (SymbolLevel::tryFrom($lastSegment) !== null) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open(ComputedMetricEntryKeys::nameSegments($name), $name),
                ComputedMetricRefusalWording::nameEndsInALevelWord($name, $lastSegment, FindingChannel::LEVEL_SEPARATOR),
            );
        }
    }

    /**
     * The name-grammar invariant {@see ComputedMetricDefinition} enforces at
     * construction is refused here first, so that a user-defined metric with a
     * malformed name never reaches the constructor at all.
     *
     * @throws ConfigurationRefusal
     */
    private static function refuseInvalidNameGrammar(string $name): void
    {
        if (ComputedMetricDefinition::isValidName($name)) {
            return;
        }

        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open(ComputedMetricEntryKeys::nameSegments($name), $name),
            ComputedMetricRefusalWording::nameGrammar($name, ComputedMetricDefinition::NAME_TEMPLATE),
        );
    }

    /**
     * The repeated-level invariant {@see ComputedMetricDefinition} enforces at
     * construction is refused here first, from both {@see merge()} and
     * {@see create()}, so that a `levels:` list with a repeat never reaches
     * the constructor.
     *
     * @param list<SymbolLevel> $levels
     *
     * @throws ConfigurationRefusal
     */
    private static function refuseDuplicateLevel(array $levels, string $name): void
    {
        if (!ComputedMetricDefinition::hasDuplicateLevel($levels)) {
            return;
        }

        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), 'levels'], 'levels'),
            ComputedMetricRefusalWording::duplicateLevel($name),
        );
    }

    /**
     * `computed_metrics.*.levels` entries are spelled from the same level
     * vocabulary as everywhere else ({@see SymbolLevel}), not from a private
     * word list of this capability's own. `callable` and `file` are therefore
     * recognised as real level words and refused here for being outside
     * {@see ComputedMetricEntryKeys::REPORTING_LEVELS}, rather than falling
     * through to the generic "not a level at all" message a stray word gets.
     *
     * @throws ConfigurationRefusal
     */
    private static function mapLevel(mixed $level, string $name): SymbolLevel
    {
        $written = \is_string($level) ? $level : get_debug_type($level);
        $symbolLevel = \is_string($level) ? SymbolLevel::tryFrom($level) : null;

        if ($symbolLevel === null) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), 'levels'], 'levels'),
                ComputedMetricRefusalWording::levelWordNotALevelAtAll($written),
            );
        }

        if (!\in_array($symbolLevel, ComputedMetricEntryKeys::REPORTING_LEVELS, true)) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), 'levels'], 'levels'),
                ComputedMetricRefusalWording::levelWordNotAReportingLevel($written, self::reportingLevelWords()),
            );
        }

        return $symbolLevel;
    }

    /** @return list<string> */
    private static function reportingLevelWords(): array
    {
        return array_map(
            static fn(SymbolLevel $level): string => $level->value,
            ComputedMetricEntryKeys::REPORTING_LEVELS,
        );
    }

    /**
     * Resolves threshold overrides from config, supporting both 'threshold' shorthand
     * and explicit 'warning'/'error' keys with mutual exclusion.
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationRefusal
     *
     * @return array{warningThreshold: ?float, errorThreshold: ?float}
     */
    private static function thresholds(array $config, ?float $defaultWarning, ?float $defaultError, string $name): array
    {
        $hasThreshold = \array_key_exists('threshold', $config);
        $hasWarning = \array_key_exists('warning', $config);
        $hasError = \array_key_exists('error', $config);

        if ($hasThreshold && ($hasWarning || $hasError)) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), 'threshold'], 'threshold'),
                ComputedMetricRefusalWording::thresholdMixedWithGraduated(),
            );
        }

        if ($hasThreshold) {
            $rawThreshold = $config[RuleOptionKey::THRESHOLD];

            // threshold: null means "not set" — fall back to defaults (consistent with ThresholdParser)
            if ($rawThreshold === null) {
                return ['warningThreshold' => $defaultWarning, 'errorThreshold' => $defaultError];
            }

            $value = self::threshold($rawThreshold, $name, 'threshold');

            return ['warningThreshold' => $value, 'errorThreshold' => $value];
        }

        return [
            'warningThreshold' => $hasWarning ? self::thresholdOrNull($config[RuleOptionKey::WARNING], $name, 'warning') : $defaultWarning,
            'errorThreshold' => $hasError ? self::thresholdOrNull($config[RuleOptionKey::ERROR], $name, 'error') : $defaultError,
        ];
    }

    /**
     * `null` means "not set"; anything else must be numeric.
     *
     * @throws ConfigurationRefusal
     */
    private static function thresholdOrNull(mixed $value, string $name, string $key): ?float
    {
        return $value === null ? null : self::threshold($value, $name, $key);
    }

    /** @throws ConfigurationRefusal */
    private static function threshold(mixed $value, string $name, string $key): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        throw self::leafRefusal($name, $key, ComputedMetricShapeRefusalWording::mustBeANumber($name, $key, $value));
    }

    private static function leafRefusal(string $name, string $key, string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), $key], $key),
            $summary,
        );
    }
}
