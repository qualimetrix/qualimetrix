<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use InvalidArgumentException;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Symbol\SymbolType;
use RuntimeException;

/**
 * Merges default computed metric definitions with user overrides from YAML
 * and validates the result (syntax, coverage, circular deps, references).
 */
final class ComputedMetricsConfigResolver
{
    public function __construct(
        private readonly ComputedMetricFormulaValidator $formulaValidator,
    ) {}

    /**
     * @param array<string, mixed> $rawConfig The 'computed_metrics' section from YAML
     *
     * @return list<ComputedMetricDefinition>
     */
    public function resolve(array $rawConfig): array
    {
        // 1. Start with defaults
        $definitions = ComputedMetricDefaults::getDefaults();

        // 2. Apply user overrides
        foreach ($rawConfig as $name => $overrides) {
            if (!\is_array($overrides)) {
                continue;
            }

            // Handle enabled: false
            if (isset($overrides[RuleOptionKey::ENABLED]) && $overrides[RuleOptionKey::ENABLED] === false) {
                unset($definitions[$name]);

                continue;
            }

            if (isset($definitions[$name])) {
                // Merge override into existing (health.*)
                $definitions[$name] = $this->mergeDefinition($definitions[$name], $overrides);
            } elseif (str_starts_with($name, 'health.')) {
                throw new RuntimeException(\sprintf(
                    'Computed metric name "%s" uses reserved "health.*" prefix. '
                    . 'Use "computed.*" prefix for user-defined metrics.',
                    $name,
                ));
            } else {
                // New user-defined metric
                $definitions[$name] = $this->createDefinition($name, $overrides);
            }
        }

        $result = array_values($definitions);

        // 3. Validate
        $this->formulaValidator->validate($result);

        return $result;
    }

    /**
     * Merges user overrides into an existing definition.
     *
     * @param array<string, mixed> $overrides
     */
    private function mergeDefinition(ComputedMetricDefinition $base, array $overrides): ComputedMetricDefinition
    {
        $formulas = $base->formulas;

        // 'formula' (singular) is shorthand — overrides ALL levels with one formula.
        // This replaces any existing per-level formulas (including specialized ones
        // like health.coupling's project formula). If the user wants to override
        // only specific levels, they should use 'formulas' (plural) instead.
        if (isset($overrides['formula']) && \is_string($overrides['formula'])) {
            $shorthand = $overrides['formula'];
            foreach (['class', 'namespace', 'project'] as $levelKey) {
                $formulas[$levelKey] = $shorthand;
            }
        }

        // 'formulas' (plural) overrides per-level
        if (isset($overrides['formulas']) && \is_array($overrides['formulas'])) {
            foreach ($overrides['formulas'] as $levelKey => $formula) {
                if (\is_string($formula)) {
                    $formulas[$levelKey] = $formula;
                }
            }
        }

        $levels = $base->levels;
        if (isset($overrides['levels']) && \is_array($overrides['levels'])) {
            $levels = array_values(array_map($this->mapLevel(...), $overrides['levels']));
        }

        $thresholds = $this->resolveThresholdOverrides($overrides, $base->warningThreshold, $base->errorThreshold);

        return new ComputedMetricDefinition(
            name: $base->name,
            formulas: $formulas,
            description: isset($overrides['description']) && \is_string($overrides['description'])
                ? $overrides['description']
                : $base->description,
            levels: $levels,
            inverted: isset($overrides['inverted']) && \is_bool($overrides['inverted'])
                ? $overrides['inverted']
                : $base->inverted,
            warningThreshold: $thresholds['warningThreshold'],
            errorThreshold: $thresholds['errorThreshold'],
        );
    }

    /**
     * Creates a new user-defined computed metric definition.
     *
     * @param array<string, mixed> $config
     */
    private function createDefinition(string $name, array $config): ComputedMetricDefinition
    {
        $formulas = [];

        // 'formula' (singular) shorthand
        if (isset($config['formula']) && \is_string($config['formula'])) {
            $shorthand = $config['formula'];
            $formulas = ['class' => $shorthand, 'namespace' => $shorthand, 'project' => $shorthand];
        }

        // 'formulas' (plural) per-level — takes precedence
        if (isset($config['formulas']) && \is_array($config['formulas'])) {
            foreach ($config['formulas'] as $levelKey => $formula) {
                if (\is_string($formula)) {
                    $formulas[$levelKey] = $formula;
                }
            }
        }

        $levels = [SymbolType::Namespace_, SymbolType::Project];
        if (isset($config['levels']) && \is_array($config['levels'])) {
            $levels = array_values(array_map($this->mapLevel(...), $config['levels']));
        }

        $thresholds = $this->resolveThresholdOverrides($config, null, null);

        return new ComputedMetricDefinition(
            name: $name,
            formulas: $formulas,
            description: isset($config['description']) && \is_string($config['description'])
                ? $config['description']
                : '',
            levels: $levels,
            inverted: isset($config['inverted']) && $config['inverted'] === true,
            warningThreshold: $thresholds['warningThreshold'],
            errorThreshold: $thresholds['errorThreshold'],
        );
    }

    private function mapLevel(string $level): SymbolType
    {
        return match ($level) {
            'class' => SymbolType::Class_,
            'namespace' => SymbolType::Namespace_,
            'project' => SymbolType::Project,
            default => throw new RuntimeException(\sprintf('Invalid computed metric level: "%s"', $level)),
        };
    }

    /**
     * Resolves threshold overrides from config, supporting both 'threshold' shorthand
     * and explicit 'warning'/'error' keys with mutual exclusion.
     *
     * @param array<string, mixed> $config
     *
     * @return array{warningThreshold: ?float, errorThreshold: ?float}
     */
    private function resolveThresholdOverrides(array $config, ?float $defaultWarning, ?float $defaultError): array
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
            $value = $this->resolveThreshold($config[RuleOptionKey::THRESHOLD]);

            // threshold: null means "not set" — fall back to defaults (consistent with ThresholdParser)
            if ($value === null) {
                return ['warningThreshold' => $defaultWarning, 'errorThreshold' => $defaultError];
            }

            return ['warningThreshold' => $value, 'errorThreshold' => $value];
        }

        return [
            'warningThreshold' => $hasWarning ? $this->resolveThreshold($config[RuleOptionKey::WARNING]) : $defaultWarning,
            'errorThreshold' => $hasError ? $this->resolveThreshold($config[RuleOptionKey::ERROR]) : $defaultError,
        ];
    }

    private function resolveThreshold(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        return null;
    }
}
