<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Core\Rule\ChannelDeclarationReader;
use Qualimetrix\Core\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Rule\RuleNameReader;
use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionObject;
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
 * {@see \Qualimetrix\Baseline\BoundaryExplanationService} data.
 *
 * **The warning boundary, not the error one.** It is the number at which a
 * channel starts reporting, which is the boundary a user compares a baseline
 * entry against; the error threshold answers a different question
 * (`bin/qmx rules` and the violation's own message carry it).
 *
 * **A channel whose options expose no such number is left out of the map, not
 * guessed.** {@see \Qualimetrix\Baseline\EffectiveBoundary::$configuredThreshold}
 * is then `null`, which `explain` prints as "not resolvable" — distinct from a
 * configured `0`. Two shapes are read, and both are conventions the codebase
 * actually holds rather than assumptions about it:
 *
 * - a hierarchical rule's channel names its level (`…#complexity.cyclomatic.method`),
 *   so the level's own options object is asked;
 * - a multi-axis rule's channel names its axis (`…#design.type-coverage.return`),
 *   so a property named after that axis is preferred (`returnWarning`).
 *
 * Rules with neither — every occurrence channel, where a boundary is not a
 * number at all — resolve to nothing, correctly.
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
     * @param class-string<RuleInterface> $ruleClass
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

        $candidates = self::GENERIC_PROPERTIES;

        if ($axis !== null) {
            array_unshift($candidates, self::axisProperty($axis));
        }

        return self::readProperty($options, $candidates);
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
