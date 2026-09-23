<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeyRecognition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricShapeRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;

/**
 * Merges default computed metric definitions with user overrides from YAML
 * and validates the result (syntax, coverage, circular deps, references).
 *
 * The per-entry order below is part of the contract: an entry's name is
 * recognised before anything about its body is read, and its keys are walked
 * before the `enabled: false` branch — otherwise a disabled entry would
 * swallow a typo in a sibling key, which is exactly the silent acceptance
 * this stage exists to close.
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
            $this->applyEntry((string) $name, $overrides, $definitions, $disabledHealth);
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
     * Applies one raw `computed_metrics:` entry to the working definition
     * map, in the order the class docblock describes.
     *
     * @param array<string, ComputedMetricDefinition> $definitions
     * @param list<string> $disabledHealth
     *
     * @param-out array<string, ComputedMetricDefinition> $definitions
     * @param-out list<string> $disabledHealth
     */
    private function applyEntry(string $name, mixed $overrides, array &$definitions, array &$disabledHealth): void
    {
        // Step 1 — the name is recognised before anything about its body is
        // read: a `health.*` name must name one of the six known dimensions,
        // and any other name must follow the grammar, whatever the entry goes
        // on to say — including nothing at all.
        $isHealth = str_starts_with($name, 'health.');
        if ($isHealth && !isset($definitions[$name])) {
            $this->refuseUnknownHealthDimension($name);
        }
        if (!$isHealth) {
            self::refuseInvalidNameGrammar($name);
        }

        // Step 2 — `name: ~` is the entry written without a body, read as
        // `name: {}`: the defaults for a built-in dimension, and for a user
        // metric whatever refusal an empty body earns. It is not an omitted
        // entry — the name was written, so there is a metric to answer for.
        $overrides ??= [];
        self::assertEntryIsMap($name, $overrides);

        // Step 3 — every key of the entry, and the shape of `formulas:`, is
        // walked before the `enabled: false` branch below, so a disabled
        // entry cannot hide a typo in a sibling key.
        ComputedMetricEntryKeyRecognition::refuseUnknownKeys($overrides, $name);
        self::assertEnabledIsBoolean($name, $overrides);

        // Step 4 — enabled: false.
        if (isset($overrides[RuleOptionKey::ENABLED]) && $overrides[RuleOptionKey::ENABLED] === false) {
            self::applyDisable($name, $isHealth, $definitions, $disabledHealth);

            return;
        }

        // Step 5 — merge() / create(). A `health.*` name reaches here only
        // known: an unknown one already refused in step 1.
        $definitions[$name] = isset($definitions[$name])
            ? ComputedMetricOverrideReader::merge($definitions[$name], $overrides)
            : ComputedMetricOverrideReader::create($name, $overrides);
    }

    /**
     * Step 1's grammar check for a name outside `health.*`.
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
     * Step 2's own check, split out of {@see self::applyEntry()} to keep
     * that method's cyclomatic weight readable.
     *
     * @phpstan-assert array<string, mixed> $overrides
     *
     * @throws ConfigurationRefusal
     */
    private static function assertEntryIsMap(string $name, mixed $overrides): void
    {
        if (!\is_array($overrides)) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open(ComputedMetricEntryKeys::nameSegments($name), $name),
                ComputedMetricShapeRefusalWording::entryNotAMap($name, $overrides),
            );
        }
    }

    /**
     * Step 3's `enabled:` shape check, split out of {@see self::applyEntry()}
     * for the same reason {@see self::assertEntryIsMap()} is.
     *
     * @param array<string, mixed> $overrides
     *
     * @throws ConfigurationRefusal
     */
    private static function assertEnabledIsBoolean(string $name, array $overrides): void
    {
        if (\array_key_exists(RuleOptionKey::ENABLED, $overrides) && !\is_bool($overrides[RuleOptionKey::ENABLED])) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($name), 'enabled'], 'enabled'),
                ComputedMetricShapeRefusalWording::mustBeABoolean($name, 'enabled', $overrides[RuleOptionKey::ENABLED]),
            );
        }
    }

    /**
     * Step 4's own branch, split out of {@see self::applyEntry()} to keep
     * that method's cyclomatic weight readable.
     *
     * @param array<string, ComputedMetricDefinition> $definitions
     * @param list<string> $disabledHealth
     *
     * @param-out array<string, ComputedMetricDefinition> $definitions
     * @param-out list<string> $disabledHealth
     */
    private static function applyDisable(string $name, bool $isHealth, array &$definitions, array &$disabledHealth): void
    {
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

            return;
        }

        unset($definitions[$name]);
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
        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::closed(
                ComputedMetricEntryKeys::nameSegments($name),
                substr($name, \strlen('health.')),
                ComputedMetricEntryKeys::acceptedHealthNames(),
            ),
            ComputedMetricRefusalWording::unknownHealthDimension($name, ComputedMetricEntryKeys::acceptedHealthNames()),
        );
    }
}
