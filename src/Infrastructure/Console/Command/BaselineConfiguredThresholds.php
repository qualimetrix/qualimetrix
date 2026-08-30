<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Throwable;

/**
 * The `qmx.yaml` half of what `baseline:explain` prints: the warning boundary
 * each channel is configured with, keyed by channel name and then by the level
 * the number applies at.
 *
 * **Why this class reads what levels a channel declares.** A channel reports at
 * every level its declaration names, and the boundaries of two levels of one
 * hierarchical rule are separate numbers, so a map keyed by the channel alone
 * would have to pick one level and print the choice as a fact. The levels are
 * therefore taken from the declaration — the authority on them — and every
 * declared level gets its own row. Reading them here decides nothing about
 * authored text: this class enumerates configuration, and the one place that
 * rules on an authored `channel:level` pair is
 * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing}.
 * `ChannelLevelRefusalTopologyTest` holds that boundary, and pins this class as
 * a reader that refuses nothing.
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
 * (`bin/qmx rules` and the finding's own message carry it). "Starts" is not
 * always "at or above": a channel declared `WorseDirection::Lower` — type
 * coverage, maintainability, data class — starts reporting at or below it.
 * The map carries the number, and the channel's own declaration carries the
 * direction.
 *
 * **The number is asked for, not guessed.** Every options class that holds a
 * boundary says which one it is through
 * {@see ThresholdAwareOptionsInterface::warningBoundary()}. The reader this
 * replaced tried three property names, a name derived from the channel's
 * suffix, and a count of `…Warning` properties to detect ambiguity — which
 * answered a different question (*how is the property spelled*) than the one
 * asked (*what does the object decide with*), and printed nothing for
 * `coupling.distance`, whose `maxDistanceWarning` was not on the list.
 *
 * **The value is what the object holds, and this class asks an object no
 * override has touched.** {@see RuleOptionsFactory} builds options from
 * configuration alone, so "configured" is a property of who is asking rather
 * than of the method; on a copy from `withOverride()` the same call reports the
 * overridden number.
 *
 * **A channel whose options hold no such number is left out of the map, not
 * guessed.** {@see \Qualimetrix\Analysis\Policy\Baseline\EffectiveBoundary::$configuredThreshold}
 * is then `null`, which `explain` prints as "not resolvable" — distinct from a
 * configured `0`. Two shapes reach that outcome, and they are different
 * statements even though the column cannot show the difference:
 *
 * - the options are not {@see ThresholdAwareOptionsInterface} at all, which is
 *   every occurrence detector: severity there comes from "more than zero
 *   occurrences", a comparison no configuration moves;
 * - the class answers
 *   {@see \Qualimetrix\Analysis\Finding\Contract\Rule\NoConfiguredBoundary::MoreThanOneBoundary},
 *   holding two and unable to tell which was applied. **A wrong number is
 *   worse than a missing one** — the user acts on it.
 *
 * A hierarchical rule's options hold one object per level, so the object of the
 * level being resolved is asked. The level always comes from the declaration,
 * never parsed back out of the channel code: the code spells the level today
 * and will not always, and a reader of the name fails by printing nothing
 * rather than by failing. A level the declaration names and the options do not
 * support yields no row, which is a mismatch worth one missing line and not a
 * failure.
 */
final readonly class BaselineConfiguredThresholds
{
    public function __construct(
        private RuleRegistryInterface $rules,
        private RuleOptionsFactory $optionsFactory,
    ) {}

    /**
     * @return array<string, array<string, int|float>> channel key => level value => configured warning boundary
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

            foreach ($declarations as $channelKey => $declaration) {
                foreach ($declaration->levels as $level) {
                    $threshold = self::thresholdFor($options, $level);

                    if ($threshold !== null) {
                        $thresholds[$channelKey][$level->value] = $threshold;
                    }
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

    /**
     * The boundary one channel is judged against **at one level**.
     *
     * Resolved per level rather than per channel because a channel reports at
     * more than one now, and a hierarchical rule's two levels have separate
     * boundaries: one number keyed by the channel alone would have to pick a
     * level and print the choice as a fact. The channel itself is not passed:
     * three options classes serve more than one channel and none of them holds
     * two different boundaries, so the object answers for itself.
     */
    private static function thresholdFor(RuleOptionsInterface $options, SymbolLevel $level): int|float|null
    {
        $target = $options instanceof HierarchicalRuleOptionsInterface
            ? self::levelOptions($options, $level)
            : $options;

        if (!$target instanceof ThresholdAwareOptionsInterface) {
            return null;
        }

        $boundary = $target->warningBoundary();

        return \is_int($boundary) || \is_float($boundary) ? $boundary : null;
    }

    private static function levelOptions(HierarchicalRuleOptionsInterface $options, SymbolLevel $level): ?LevelOptionsInterface
    {
        try {
            return $options->forLevel($level);
        } catch (Throwable) {
            // A rule declaring a channel for a level its options do not
            // support is a mismatch worth no more than a missing line here.
            return null;
        }
    }
}
