<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use InvalidArgumentException;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\HealthDimension;
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
        private readonly HealthFormulaExcluder $healthFormulaExcluder,
    ) {}

    /**
     * @param array<string, mixed> $rawConfig The 'computed_metrics' section from YAML
     * @param list<string> $excludeHealth Health dimensions excluded from scoring. Each entry
     *                                    may be a bare name (`'typing'`) or fully-qualified
     *                                    (`'health.typing'`); both are accepted and normalized
     *                                    internally. Disabled `health.*` metrics (declared as
     *                                    `enabled: false` in YAML) are folded into the same
     *                                    exclusion pipeline so both disable paths produce the
     *                                    same renormalized weights in `health.overall`.
     *
     * @return list<ComputedMetricDefinition>
     */
    public function resolve(array $rawConfig, array $excludeHealth = []): array
    {
        // 1. Start with defaults
        $definitions = ComputedMetricDefaults::getDefaults();

        // Normalize excludeHealth shape up-front: both 'typing' and 'health.typing' are valid
        // inputs; converting to the canonical 'health.*' form makes array_unique() below
        // actually dedupe across the two sources.
        $normalizedExcludeHealth = array_map(
            static fn(string $dim): string => str_starts_with($dim, 'health.') ? $dim : 'health.' . $dim,
            $excludeHealth,
        );

        // 2. Apply user overrides, collecting disabled health.* metrics
        $disabledHealth = [];
        foreach ($rawConfig as $name => $overrides) {
            if (!\is_array($overrides)) {
                continue;
            }

            // Handle enabled: false
            if (isset($overrides[RuleOptionKey::ENABLED]) && $overrides[RuleOptionKey::ENABLED] === false) {
                if (str_starts_with($name, 'health.') && $name !== HealthDimension::Overall->value) {
                    // Route disabled health dimensions through HealthFormulaExcluder so that
                    // dependent formulas (e.g. `health.overall` referencing the disabled dim
                    // via `??`) get their weights renormalized — same outcome as `exclude_health`.
                    // The excluder handles the actual removal, so we DO NOT unset here.
                    //
                    // `health.overall` itself has no current dependents and is routed to the
                    // simple unset branch below. If a future health.* metric starts depending
                    // on `health.overall`, route it through the excluder as well.
                    $disabledHealth[] = $name;

                    continue;
                }

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

        // 3. Apply combined exclude-health (explicit + auto-collected from enabled:false)
        //    BEFORE validation so that the formula validator sees the final state.
        //    Disabled health dimensions are validated separately to produce an error message
        //    that points at the actual config source (`computed_metrics.health.X.enabled`)
        //    instead of `--exclude-health`.
        $this->validateDisabledHealthDimensions($disabledHealth, $result);

        $combinedExclusions = array_values(array_unique([...$disabledHealth, ...$normalizedExcludeHealth]));
        if ($combinedExclusions !== []) {
            $result = $this->healthFormulaExcluder->applyExcludeHealth($result, $combinedExclusions);
        }

        // 4. Validate
        $this->formulaValidator->validate($result);

        return $result;
    }

    /**
     * Validates that every name in `enabled: false` (for `health.*` metrics) matches a real
     * default dimension. Produces a clearer error than HealthFormulaExcluder would, because
     * the source is the user's `computed_metrics` section, not `--exclude-health`.
     *
     * @param list<string> $disabledHealth
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateDisabledHealthDimensions(array $disabledHealth, array $definitions): void
    {
        if ($disabledHealth === []) {
            return;
        }

        $known = [];
        foreach ($definitions as $definition) {
            if (str_starts_with($definition->name, 'health.') && $definition->name !== HealthDimension::Overall->value) {
                $known[$definition->name] = true;
            }
        }

        foreach ($disabledHealth as $name) {
            if (!isset($known[$name])) {
                throw new InvalidArgumentException(\sprintf(
                    'Unknown health dimension "%s" disabled via "computed_metrics.%s.enabled: false". '
                    . 'Valid dimensions: %s.',
                    $name,
                    $name,
                    implode(', ', array_keys($known)),
                ));
            }
        }
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
