<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;

/**
 * What a configuration lets each producer do, per declared level.
 *
 * A fact about configuration rather than about a run, which is why it is
 * computed here from the registered rules and the channel universe instead of
 * being recorded by the execution that happens to want it. {@see RuleExecution}
 * asks it once per {@see RuleExecutionInterface::levelActivity()} and hands the
 * same answer to every result it builds.
 */
final readonly class ConfiguredLevelActivity
{
    /**
     * @param list<RuleInterface> $rules Every registered rule, not only the instances a run executed
     * @param ChannelIdentityInterface|null $channelIdentity The run's channel universe, when one is installed
     */
    public function __construct(
        private array $rules,
        private ?ChannelIdentityInterface $channelIdentity,
    ) {}

    /**
     * Which producer/level pairs this configuration lets run, asked of the
     * rules themselves.
     *
     * Every registered rule is asked, not only the instances this run
     * executed: a rule a selector switched off still has an honest answer
     * about what its *configuration* allows, and the two questions are
     * deliberately kept apart — callers pair this with
     * {@see Contract\Rule\RuleSelector::isProducerEnabled()} rather than reading one fact
     * that silently folds both.
     *
     * Two completions on top of the rules' own answers, both about channels a
     * rule does not declare itself:
     *
     * - a **configuration validator** declares its own channels and runs
     *   inside its producer's slot ({@see RuleExecutionInterface::execute()}), so its
     *   levels belong to that producer and are live exactly when the instance ran at all.
     *   Measured on this tree: five such channels, all
     *   `architecture.*` at project level, produced by
     *   `architecture.layer-violation`, none of them declared by the rule
     *   class;
     * - a **classless producer** has no instance to ask. It is absent here
     *   rather than present and false, and absence is not disablement — see
     *   {@see Contract\RuleExecutionResult::$levelActivity}.
     */
    public function activity(): LevelActivity
    {
        return LevelActivity::fromMap($this->withValidatorChannels($this->asTheRulesAnswer()));
    }

    /**
     * What the rules say about themselves, merged: two rules never speak for
     * the same producer today, and `||` says what the merge would mean if they
     * ever did — the pair ran if any instance behind it ran.
     *
     * @return array<string, array<string, bool>>
     */
    private function asTheRulesAnswer(): array
    {
        $activity = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->levelActivity() as $producer => $levels) {
                foreach ($levels as $level => $ran) {
                    $activity[$producer][$level] = ($activity[$producer][$level] ?? false) || $ran;
                }
            }
        }

        return $activity;
    }

    /**
     * Fills in the levels a producer owns through a configuration validator's
     * channels rather than through its own declarations. Such a channel runs
     * inside its producer's slot ({@see RuleExecutionInterface::execute()}), so it is live exactly
     * when that instance ran at all.
     *
     * `$instanceRan` reads that off the answer the rule already gave, and the
     * two are not the same statement: the rule speaks about its own levels
     * being switched on, while a validator's channel asks whether the slot was
     * entered. They coincide because the only thing that keeps an instance out
     * of the loop is its own configuration — a rule with every level off still
     * enters it, which is why this is an over- rather than an under-estimate,
     * and an over-estimate here can only leave a verdict where it already was.
     * A future selection that drops instances for a different reason would
     * make them part company, and this is where that would have to be read
     * again.
     *
     * @param array<string, array<string, bool>> $activity
     *
     * @return array<string, array<string, bool>>
     */
    private function withValidatorChannels(array $activity): array
    {
        if ($this->channelIdentity === null) {
            return $activity;
        }

        foreach ($this->channelIdentity->channels() as $channel) {
            $producer = $this->channelIdentity->producerOf($channel->code);

            if ($producer === null || !isset($activity[$producer])) {
                continue;
            }

            $instanceRan = \in_array(true, $activity[$producer], true);

            foreach ($this->channelIdentity->levelsOf($channel->code) as $level) {
                $activity[$producer][$level->value] ??= $instanceRan;
            }
        }

        return $activity;
    }
}
