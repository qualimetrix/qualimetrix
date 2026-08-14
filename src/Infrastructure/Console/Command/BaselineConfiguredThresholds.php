<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionObject;
use ReflectionProperty;
use Throwable;

/**
 * The `qmx.yaml` half of what `baseline:explain` prints: the warning boundary
 * each channel is configured with, keyed by {@see ViolationChannel::toKey()}.
 *
 * **Why it lives here and not in `Baseline`.** `qmx.yaml`'s own architecture
 * section allows the `Baseline` layer to depend on `Core` and nothing else,
 * while resolving a rule's configured options means going through
 * {@see RuleOptionsFactory}, which is `Configuration`. The command is already
 * on the far side of that boundary, so it resolves the numbers and hands
 * {@see \Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService} data.
 *
 * **The warning boundary, not the error one.** It is the number at which a
 * channel starts reporting, which is the boundary a user compares a baseline
 * entry against; the error threshold answers a different question
 * (`bin/qmx rules` and the violation's own message carry it).
 *
 * **A channel whose options expose no such number is left out of the map, not
 * guessed.** {@see \Qualimetrix\Analysis\Policy\Baseline\EffectiveBoundary::$configuredThreshold}
 * is then `null`, which `explain` prints as "not resolvable" — distinct from a
 * configured `0`. Two shapes are read, and both are conventions the codebase
 * actually holds rather than assumptions about it:
 *
 * - a hierarchical rule's channel names its level (`…#complexity.cyclomatic.callable`),
 *   so the level's own options object is asked;
 * - a multi-axis rule's channel names its axis (`…#design.type-coverage.return`),
 *   so a property named after that axis is preferred (`returnWarning`).
 *
 * Rules with neither resolve to nothing, correctly.
 *
 * **Occurrence and "no number" are not the same thing**, and the earlier
 * wording here equated them. `coupling.class-rank` is an occurrence channel —
 * a baseline entry for it bounds a count, because a PageRank is renormalised
 * over the whole project and is not a boundary in a later run's units — yet
 * its options do configure the `0.02` the rule fires above, and printing that
 * number answers exactly the question this line of `explain` asks. What an
 * occurrence channel has no number for is the *entry*, which is a different
 * column of the same output.
 *
 * **And a channel that names no axis while its options hold several
 * boundaries resolves to nothing too, deliberately.** `LongParameterListRule`
 * emits one channel and judges against two thresholds: `warning` for an
 * ordinary method, `voWarning` for a readonly value object's constructor. The
 * channel carries nothing that says which applied, so picking the generic
 * property prints "qmx.yaml says 4" for a nine-parameter VO constructor the
 * rule measured against 8. **A wrong number is worse than a missing one** —
 * the user acts on it — so the ambiguity is reported as an ambiguity, through
 * the same `null` the unresolvable channels use and therefore still distinct
 * from a configured `0`. The condition is structural, not a list of rule
 * names: more than one warning boundary on the options object, and no axis in
 * the channel to choose between them.
 */
final readonly class BaselineConfiguredThresholds
{
    /**
     * Property names holding a level's or an axis's warning boundary, in the
     * order they are tried once the axis-specific name has been.
     */
    private const array GENERIC_PROPERTIES = [RuleOptionKey::WARNING, 'maxWarning', 'minWarning'];

    public function __construct(
        private RuleRegistryInterface $rules,
        private RuleOptionsFactory $optionsFactory,
    ) {}

    /**
     * @return array<string, int|float> channel key => configured warning boundary
     */
    public function resolve(): array
    {
        $thresholds = [];

        foreach ($this->rules->getClasses() as $ruleClass) {
            $declarations = ChannelDeclarationReader::read($ruleClass);

            if ($declarations === []) {
                continue;
            }

            $options = $this->optionsFor($ruleClass);

            if ($options === null) {
                continue;
            }

            foreach (array_keys($declarations) as $channelKey) {
                $threshold = self::thresholdFor($options, ViolationChannel::fromKey($channelKey));

                if ($threshold !== null) {
                    $thresholds[$channelKey] = $threshold;
                }
            }
        }

        return $thresholds;
    }

    /**
     * @param class-string<RuleDefinitionInterface> $ruleClass
     */
    private function optionsFor(string $ruleClass): ?RuleOptionsInterface
    {
        try {
            return $this->optionsFactory->create(RuleNameReader::read($ruleClass), $ruleClass::getOptionsClass());
        } catch (Throwable) {
            // A rule whose options cannot be built under the current
            // configuration has no configured boundary to report. That is a
            // gap in one line of `explain`'s output, not a reason to refuse
            // to explain anything.
            return null;
        }
    }

    private static function thresholdFor(RuleOptionsInterface $options, ViolationChannel $channel): int|float|null
    {
        $axis = self::axisOf($channel);
        $level = $axis !== null ? RuleLevel::tryFrom($axis) : null;

        if ($level !== null && $options instanceof HierarchicalRuleOptionsInterface) {
            $levelOptions = self::levelOptions($options, $level);

            return $levelOptions !== null ? self::readProperty($levelOptions, self::GENERIC_PROPERTIES) : null;
        }

        if ($axis !== null) {
            $onAxis = self::readProperty($options, [self::axisProperty($axis)]);

            if ($onAxis !== null) {
                return $onAxis;
            }
        }

        // The channel names no boundary of its own, so a generic property is
        // only an answer while there is one to be generic about. Where the
        // options hold several, nothing in the channel says which the rule
        // applied to the finding being explained, and the first one that
        // happens to exist would be a guess printed as a fact.
        if (self::countsWarningBoundaries($options) > 1) {
            return null;
        }

        return self::readProperty($options, self::GENERIC_PROPERTIES);
    }

    /**
     * How many distinct warning boundaries the options expose.
     *
     * Counted from the object rather than from a list of known rules: the
     * property is "this rule judges one channel against more than one
     * boundary", and a rule added tomorrow with a second `…Warning` gets the
     * honest answer without anybody remembering to extend a list here.
     * `error` counterparts are not counted — the boundary this class reports
     * is the warning one, and a rule with `warning`/`error` is not ambiguous
     * about which of them is being asked for.
     */
    private static function countsWarningBoundaries(RuleOptionsInterface $options): int
    {
        $found = 0;

        foreach ((new ReflectionObject($options))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if ($name !== RuleOptionKey::WARNING && !str_ends_with($name, 'Warning')) {
                continue;
            }

            $value = $property->getValue($options);

            if (\is_int($value) || \is_float($value)) {
                ++$found;
            }
        }

        return $found;
    }

    private static function levelOptions(HierarchicalRuleOptionsInterface $options, RuleLevel $level): ?LevelOptionsInterface
    {
        try {
            return $options->forLevel($level);
        } catch (Throwable) {
            // A rule declaring a channel for a level its options do not
            // support is a mismatch worth no more than a missing line here.
            return null;
        }
    }

    /**
     * The part of the violation code that follows the rule name — the level
     * or the axis the channel reports on, or `null` when the code is the rule
     * name itself.
     */
    private static function axisOf(ViolationChannel $channel): ?string
    {
        $prefix = $channel->ruleName . '.';

        if (!str_starts_with($channel->violationCode, $prefix)) {
            return null;
        }

        $axis = substr($channel->violationCode, \strlen($prefix));

        return $axis === '' ? null : $axis;
    }

    /**
     * `return` → `returnWarning`, `max-distance` → `maxDistanceWarning`.
     */
    private static function axisProperty(string $axis): string
    {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $axis))));

        return $camel . ucfirst(RuleOptionKey::WARNING);
    }

    /**
     * @param list<string> $candidates
     */
    private static function readProperty(object $target, array $candidates): int|float|null
    {
        $reflection = new ReflectionObject($target);

        foreach ($candidates as $name) {
            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);

            if (!$property->isPublic()) {
                continue;
            }

            $value = $property->getValue($target);

            if (\is_int($value) || \is_float($value)) {
                return $value;
            }
        }

        return null;
    }
}
