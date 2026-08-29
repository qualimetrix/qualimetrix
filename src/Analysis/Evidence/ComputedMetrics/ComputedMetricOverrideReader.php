<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use InvalidArgumentException;
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
 */
final class ComputedMetricOverrideReader
{
    /**
     * The levels a computed metric reports at, named once for both readers of
     * the fact: the `formula:` shorthand writes one key per level here, and
     * {@see mapLevel()} refuses every other level word against the same list.
     *
     * Three named cases rather than {@see SymbolLevel::cases()}: the set is a
     * fact about this capability — {@see ComputedMetricDefinition}'s
     * `formulas` and {@see ComputedMetricDefaults} have no `callable` or
     * `file` entry — and iterating the vocabulary would silently widen both
     * the accepted `levels:` domain and the keys the shorthand writes from
     * three to five.
     *
     * @var list<SymbolLevel>
     */
    private const array REPORTING_LEVELS = [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project];

    /**
     * Merges user overrides into an existing definition.
     *
     * @param array<string, mixed> $overrides
     */
    public static function merge(ComputedMetricDefinition $base, array $overrides): ComputedMetricDefinition
    {
        $thresholds = self::thresholds($overrides, $base->warningThreshold, $base->errorThreshold);

        return new ComputedMetricDefinition(
            name: $base->name,
            formulas: self::formulas($overrides, $base->formulas),
            description: self::description($overrides, $base->description),
            levels: self::levels($overrides, $base->levels),
            inverted: self::inverted($overrides) ?? $base->inverted,
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
     */
    public static function create(string $name, array $config): ComputedMetricDefinition
    {
        self::assertNameDoesNotEndInALevel($name);

        $thresholds = self::thresholds($config, null, null);

        return new ComputedMetricDefinition(
            name: $name,
            formulas: self::formulas($config, []),
            description: self::description($config, ''),
            levels: self::levels($config, [SymbolLevel::Namespace_, SymbolLevel::Project]),
            inverted: self::inverted($config) ?? false,
            warningThreshold: $thresholds['warningThreshold'],
            errorThreshold: $thresholds['errorThreshold'],
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $defaults
     *
     * @return array<string, string>
     */
    private static function formulas(array $config, array $defaults): array
    {
        $formulas = $defaults;

        // 'formula' (singular) is shorthand — overrides ALL levels with one formula.
        // This replaces any existing per-level formulas (including specialized ones
        // like health.coupling's project formula). If the user wants to override
        // only specific levels, they should use 'formulas' (plural) instead.
        if (isset($config['formula']) && \is_string($config['formula'])) {
            foreach (self::REPORTING_LEVELS as $level) {
                $formulas[$level->value] = $config['formula'];
            }
        }

        // 'formulas' (plural) per-level — takes precedence
        if (!isset($config['formulas']) || !\is_array($config['formulas'])) {
            return $formulas;
        }

        foreach ($config['formulas'] as $levelKey => $formula) {
            if (\is_string($formula)) {
                $formulas[$levelKey] = $formula;
            }
        }

        return $formulas;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<SymbolLevel> $defaults
     *
     * @return list<SymbolLevel>
     */
    private static function levels(array $config, array $defaults): array
    {
        if (!isset($config['levels']) || !\is_array($config['levels'])) {
            return $defaults;
        }

        return array_values(array_map(self::mapLevel(...), $config['levels']));
    }

    /** @param array<string, mixed> $config */
    private static function description(array $config, string $default): string
    {
        return isset($config['description']) && \is_string($config['description'])
            ? $config['description']
            : $default;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return ?bool what the entry says, or `null` when it does not say — the
     *               caller alone knows what an unstated `inverted` falls back to
     */
    private static function inverted(array $config): ?bool
    {
        return isset($config['inverted']) && \is_bool($config['inverted']) ? $config['inverted'] : null;
    }

    /**
     * A channel's level is a coordinate read off the finding's subject, not a
     * word inside the channel name ({@see FindingChannel}). A user-defined
     * metric name ending in a level word would put the pair back into the
     * name, so it is refused at the same point the reserved `health.*` prefix
     * is refused by the resolver — the moment the name is read from
     * configuration, before it can become a channel.
     */
    private static function assertNameDoesNotEndInALevel(string $name): void
    {
        $lastDot = strrpos($name, '.');
        $lastSegment = $lastDot === false ? $name : substr($name, $lastDot + 1);

        if (SymbolLevel::tryFrom($lastSegment) !== null) {
            throw new ComputedMetricConfigurationException(\sprintf(
                'Computed metric name "%s" must not end in the level word "%s". '
                . 'A level is addressed beside the channel name, with "%s%s", not inside the name.',
                $name,
                $lastSegment,
                FindingChannel::LEVEL_SEPARATOR,
                $lastSegment,
            ));
        }
    }

    /**
     * `computed_metrics.*.levels` entries are spelled from the same level
     * vocabulary as everywhere else ({@see SymbolLevel}), not from a private
     * word list of this capability's own. `callable` and `file` are therefore
     * recognised as real level words and refused here for being outside
     * {@see REPORTING_LEVELS}, rather than falling through to the generic
     * "not a level at all" message a stray word gets.
     */
    private static function mapLevel(string $level): SymbolLevel
    {
        $symbolLevel = SymbolLevel::tryFrom($level)
            ?? throw new ComputedMetricConfigurationException(\sprintf('Invalid computed metric level: "%s"', $level));

        if (!\in_array($symbolLevel, self::REPORTING_LEVELS, true)) {
            throw new ComputedMetricConfigurationException(\sprintf(
                'Computed metric level "%s" is not supported; computed metrics report at %s only.',
                $level,
                self::reportingLevelWords(),
            ));
        }

        return $symbolLevel;
    }

    /** Renders {@see REPORTING_LEVELS} the way the refusal message names them. */
    private static function reportingLevelWords(): string
    {
        $words = array_map(
            static fn(SymbolLevel $level): string => \sprintf('"%s"', $level->value),
            self::REPORTING_LEVELS,
        );
        $last = array_pop($words);

        return implode(', ', $words) . ' or ' . $last;
    }

    /**
     * Resolves threshold overrides from config, supporting both 'threshold' shorthand
     * and explicit 'warning'/'error' keys with mutual exclusion.
     *
     * @param array<string, mixed> $config
     *
     * @return array{warningThreshold: ?float, errorThreshold: ?float}
     */
    private static function thresholds(array $config, ?float $defaultWarning, ?float $defaultError): array
    {
        $hasThreshold = \array_key_exists('threshold', $config);
        $hasWarning = \array_key_exists('warning', $config);
        $hasError = \array_key_exists('error', $config);

        if ($hasThreshold && ($hasWarning || $hasError)) {
            throw new InvalidArgumentException(
                'Cannot mix "threshold" with "warning"/"error". Use either "threshold" alone (simple mode) or "warning"/"error" (graduated mode).',
            );
        }

        if ($hasThreshold) {
            $value = self::threshold($config[RuleOptionKey::THRESHOLD]);

            // threshold: null means "not set" — fall back to defaults (consistent with ThresholdParser)
            if ($value === null) {
                return ['warningThreshold' => $defaultWarning, 'errorThreshold' => $defaultError];
            }

            return ['warningThreshold' => $value, 'errorThreshold' => $value];
        }

        return [
            'warningThreshold' => $hasWarning ? self::threshold($config[RuleOptionKey::WARNING]) : $defaultWarning,
            'errorThreshold' => $hasError ? self::threshold($config[RuleOptionKey::ERROR]) : $defaultError,
        ];
    }

    private static function threshold(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        return null;
    }
}
