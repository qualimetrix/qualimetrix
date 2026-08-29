<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;

/**
 * Merges default computed metric definitions with user overrides from YAML
 * and validates the result (syntax, coverage, circular deps, references).
 */
final class ComputedMetricsConfigResolver
{
    public function __construct(
        private readonly ComputedMetricFormulaValidator $formulaValidator,
        private readonly HealthFormulaExclusionInterface $healthFormulaExcluder,
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
                $definitions[$name] = ComputedMetricOverrideReader::merge($definitions[$name], $overrides);
            } elseif (str_starts_with($name, 'health.')) {
                throw new ComputedMetricConfigurationException(\sprintf(
                    'Computed metric name "%s" uses reserved "health.*" prefix. '
                    . 'Use "computed.*" prefix for user-defined metrics.',
                    $name,
                ));
            } else {
                // New user-defined metric
                $definitions[$name] = ComputedMetricOverrideReader::create($name, $overrides);
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
}
