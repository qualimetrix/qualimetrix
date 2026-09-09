<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeyRecognition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;

/**
 * Merges default computed metric definitions with user overrides from YAML
 * and validates the result (syntax, coverage, circular deps, references).
 *
 * The per-entry order below is part of the contract
 * (`02-computed-metric-keys.md` §6): an entry's name is recognised before its
 * keys are walked, and its keys are walked before the `enabled: false` branch
 * — otherwise a disabled entry would swallow a typo in a sibling key, which
 * is exactly the silent acceptance this stage exists to close.
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
     * @throws ConfigurationRefusal
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
            $name = (string) $name;

            // Step 0 — an empty YAML block means the same as an omitted entry.
            if ($overrides === null) {
                continue;
            }

            // Step 1 — the entry itself must be a map.
            if (!\is_array($overrides)) {
                throw ConfigurationRefusal::at(
                    ConfigurationOrigin::of(ConfigurationSource::Resolved),
                    RefusedPosition::open(ComputedMetricEntryKeys::nameSegments($name), $name),
                    ComputedMetricRefusalWording::entryNotAMap($name, $overrides),
                );
            }

            // Step 2 — the name is recognised before anything about its body is
            // read: a `health.*` name must name one of the six known dimensions,
            // regardless of what the entry goes on to say.
            $isHealth = str_starts_with($name, 'health.');
            if ($isHealth && !isset($definitions[$name])) {
                $this->refuseUnknownHealthDimension($name);
            }

            // Step 3 — every key of the entry, and the shape of `formulas:`, is
            // walked before the `enabled: false` branch below, so a disabled
            // entry cannot hide a typo in a sibling key.
            ComputedMetricEntryKeyRecognition::refuseUnknownKeys($overrides, $name);

            if (\array_key_exists(RuleOptionKey::ENABLED, $overrides) && !\is_bool($overrides[RuleOptionKey::ENABLED])) {
                throw ConfigurationRefusal::at(
                    ConfigurationOrigin::of(ConfigurationSource::Resolved),
                    RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), 'enabled'], 'enabled'),
                    ComputedMetricRefusalWording::mustBeABoolean($name, 'enabled', $overrides[RuleOptionKey::ENABLED]),
                );
            }

            // Step 4 — enabled: false.
            if (isset($overrides[RuleOptionKey::ENABLED]) && $overrides[RuleOptionKey::ENABLED] === false) {
                if ($isHealth && $name !== HealthDimension::Overall->value) {
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

            // Step 5 — merge() / create(). A `health.*` name reaches here only
            // known: an unknown one already refused in step 2.
            $definitions[$name] = isset($definitions[$name])
                ? ComputedMetricOverrideReader::merge($definitions[$name], $overrides)
                : ComputedMetricOverrideReader::create($name, $overrides);
        }

        $result = array_values($definitions);

        // 3. Apply combined exclude-health (explicit + auto-collected from enabled:false)
        //    BEFORE validation so that the formula validator sees the final state.
        $combinedExclusions = array_values(array_unique([...$disabledHealth, ...$normalizedExcludeHealth]));
        if ($combinedExclusions !== []) {
            $result = $this->healthFormulaExcluder->applyExcludeHealth($result, $combinedExclusions);
        }

        // 4. Validate
        $this->formulaValidator->validate($result);

        return $result;
    }

    /**
     * The one refusal both of yesterday's two paths collapsed into
     * (`02-computed-metric-keys.md` §2): `health.<unknown>` used to answer
     * "reserved prefix" when the entry carried a formula and "unknown
     * dimension" when it carried `enabled: false`. Both intents are the same
     * mistake — a typo in one of the six known names — so both now raise the
     * same refusal, at the point the name is recognised, before either branch
     * is reached.
     *
     * @throws ConfigurationRefusal
     */
    private function refuseUnknownHealthDimension(string $name): never
    {
        throw ConfigurationRefusal::at(
            ConfigurationOrigin::of(ConfigurationSource::Resolved),
            RefusedPosition::closed(
                ComputedMetricEntryKeys::nameSegments($name),
                substr($name, \strlen('health.')),
                ComputedMetricEntryKeys::acceptedHealthNames(),
            ),
            ComputedMetricRefusalWording::unknownHealthDimension($name, ComputedMetricEntryKeys::acceptedHealthNames()),
        );
    }
}
