<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;

/**
 * Every fact the rest of the system reads off "the class named by this
 * producer", declared for the seven producers of the computed-metric family —
 * six of which have no class at all.
 *
 * A producer here is not a rule class, and cannot become one: two classes
 * declaring one `NAME` are refused by
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass},
 * and six classes over one shared body would be six copies of the same rule.
 * So the facts a rule class would carry as constants are carried here, and
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
 * reads them at container-build time exactly where it reads a class's own.
 *
 * **The split is not a preference, it is what the name spaces allow.** The six
 * built-in dimensions are a closed set — {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver}
 * merges an override onto an existing `health.*` name and refuses a new one —
 * so each can own its name. A user-defined metric's name comes out of somebody
 * else's `qmx.yaml`, read by a configuration stage that the rule-name validator
 * runs *inside*, so it can never be a name the build knows: the open half
 * shares one producer, {@see OPEN_PRODUCER_RULE_NAME}.
 *
 * Two of the three facts whose readers silently default when a producer stays
 * silent are declared here rather than left out ({@see SUPPORTS_THRESHOLD_OVERRIDE},
 * {@see CLI_ALIASES}): for a producer with no class, a forgotten fact would not
 * fail the build, it would quietly impoverish behaviour, and both are read by
 * the compiler pass at the point it reads a class's own constants.
 *
 * The third — the channels a producer declares at build time — is not a
 * constant here, and deliberately not: this family declares none, and that is
 * enforced rather than asserted. The static half of the universe is assembled
 * only from rule classes and configuration validators, and the tracked
 * `declared.txt` fixture pins its exact contents, so a family channel appearing
 * statically fails `ChannelUniverseCoverageTest` — which also names the
 * property directly. A constant repeating the same `[]` would be a claim
 * nothing reads: there is no rule saying which of the seven producers would own
 * an entry in it, so no mechanism could have used one.
 */
final class ComputedMetricChannelFamily
{
    /**
     * The producer of every user-defined computed metric, and the `NAME` of
     * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule} —
     * the family's one real class. The open half is the half a class can serve,
     * because it is the half whose channels are not knowable at build time.
     */
    public const string OPEN_PRODUCER_RULE_NAME = 'computed';

    /**
     * Spelled out rather than mapped from {@see HealthDimension}: this list and
     * that enum are the two witnesses a guard compares, and a list derived from
     * the enum would agree with it by construction instead of by measurement.
     *
     * @var list<string>
     */
    public const array HEALTH_PRODUCER_RULE_NAMES = [
        'health.complexity',
        'health.cohesion',
        'health.coupling',
        'health.typing',
        'health.maintainability',
        'health.overall',
    ];

    /** @var list<string> */
    public const array PRODUCER_RULE_NAMES = [...self::HEALTH_PRODUCER_RULE_NAMES, self::OPEN_PRODUCER_RULE_NAME];

    /** One page documents the whole family, health dimensions included. */
    public const string DOCS_PAGE = 'reference/health-scores.md';

    public const int REMEDIATION_MINUTES = 15;

    /**
     * Uniform across every producer of the family: a computed metric always
     * reports a real measured value. Its per-channel
     * {@see \Qualimetrix\Core\Observation\WorseDirection} still varies — it
     * comes from each definition's own `inverted` flag, resolved at run time —
     * which is exactly why shape and direction are two separate facts.
     */
    public const ChannelShape SHAPE = ChannelShape::Magnitude;

    /**
     * Explicitly `false`, not omitted. No producer of this family reads
     * `thresholdOverrides`, and {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRuleOptions}
     * is not threshold-aware, so declaring `true` would promise a retune the
     * runtime cannot perform.
     *
     * Naming one of these producers in `@qmx-threshold` therefore stops being
     * "no such rule" and becomes "this rule cannot be retuned" — two different
     * configuration diagnostics, and the second is the accurate one.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = false;

    /**
     * Explicitly empty. A CLI alias is a short flag for one option of one
     * producer; seven producers sharing one options class would need seven
     * aliases for the same switch.
     *
     * @var array<string, string>
     */
    public const array CLI_ALIASES = [];

    private function __construct() {}

    /**
     * The arbiter: which producer publishes a finding measured for this
     * definition.
     *
     * A function rather than a fourth copy of the rule as a map, because four
     * seams ask it — finding emission, the reverse lookup
     * ({@see \Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface::producerOf()}),
     * the forward lookup (`channelsProducedBy()`), and the run-time snapshot's
     * name-collision guard — and a map copied four times is a rule stated four
     * times.
     */
    public static function producerFor(string $definitionName): string
    {
        return \in_array($definitionName, self::HEALTH_PRODUCER_RULE_NAMES, true)
            ? $definitionName
            : self::OPEN_PRODUCER_RULE_NAME;
    }

    /**
     * Throws through {@see HealthDimension::from()} for a name that is neither
     * the open producer nor a declared dimension: this is read while the
     * container is being built, so an unknown producer must stop the build
     * rather than reach a report as an empty description.
     */
    public static function descriptionOf(string $producerRuleName): string
    {
        if ($producerRuleName === self::OPEN_PRODUCER_RULE_NAME) {
            return 'Checks user-defined computed metrics against their thresholds';
        }

        return \sprintf(
            'Checks the %s health score against its thresholds',
            HealthDimension::from($producerRuleName)->shortName(),
        );
    }
}
