<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\ProducerDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Traversable;

/**
 * Default implementation of RuleExecutionInterface.
 *
 * Filters rules at runtime based on configuration (disabled_rules, only_rules)
 * and executes only active rules. Filters individual findings by code.
 */
final class RuleExecution implements RuleExecutionInterface
{
    /** @var list<RuleInterface> */
    private readonly array $allRules;

    /** @var array<string, list<ConfigurationValidatorInterface>> producer rule name => its validators */
    private readonly array $validatorsByProducer;

    private readonly RuleSelector $ruleSelector;

    private readonly FindingExclusionLedger $exclusions;

    /** @var list<ProducerDeclaration> */
    private readonly array $classlessProducers;

    /**
     * @param iterable<RuleInterface> $rules All registered rules
     * @param iterable<ConfigurationValidatorInterface> $configurationValidators Every registered validator; each
     *                                                                           runs in its producer rule's slot, see {@see execute()}
     * @param iterable<ProducerDeclaration> $classlessProducers Producers a capability owns without a rule
     *                                                          class of their own; they are part of every
     *                                                          "registered rule" answer and of none of the
     *                                                          execution ones, because there is nothing to run
     */
    public function __construct(
        iterable $rules,
        private readonly ProfilerInterface $profiler,
        private readonly RuleConfigurationInterface $ruleOptionsRegistry,
        ?RuleSelector $ruleSelector = null,
        iterable $configurationValidators = [],
        iterable $classlessProducers = [],
        private readonly ?ChannelIdentityInterface $channelIdentity = null,
    ) {
        $this->classlessProducers = $classlessProducers instanceof Traversable
            ? iterator_to_array($classlessProducers, false)
            : array_values($classlessProducers);
        $this->allRules = $rules instanceof Traversable
            ? iterator_to_array($rules, false)
            : array_values($rules);
        $this->validatorsByProducer = self::groupByProducer($configurationValidators);
        $this->ruleSelector = $ruleSelector ?? new RuleSelector(new InMemoryRuleChannelRegistry());
        $this->exclusions = new FindingExclusionLedger($ruleOptionsRegistry);
    }

    public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult
    {
        $produced = [];
        $published = [];
        $profiler = $this->profiler;

        $this->exclusions->begin();

        $selection = $this->ruleOptionsRegistry->selection();
        foreach ($this->activeRuleInstances($selection, $restrictToProducer) as $rule) {
            $ruleName = $rule->getName();

            // One span, and the validators run inside it: a configuration
            // validator occupies its producer's slot in the execution order,
            // which is what keeps the position of its findings — and therefore
            // the order of every report that does not sort — exactly where it
            // was while the diagnostics lived in the rule class.
            $spanName = 'rule.' . $ruleName;
            $profiler->start($spanName, 'rules');
            $ruleFindings = $rule->analyze($context);
            foreach ($this->validatorsByProducer[$ruleName] ?? [] as $validator) {
                $ruleFindings = [...$ruleFindings, ...$this->validate($validator, $context)];
            }
            $profiler->stop($spanName);

            $produced = [...$produced, ...$ruleFindings];
            $published = [...$published, ...$this->published($ruleName, $ruleFindings, $selection, $restrictToProducer)];
        }

        return new RuleExecutionResult($produced, $published, $this->exclusions->stats(), $this->levelActivity());
    }

    /**
     * Which producer/level pairs this configuration lets run, asked of the
     * rules themselves.
     *
     * Every registered rule is asked, not only the instances this run
     * executed: a rule a selector switched off still has an honest answer
     * about what its *configuration* allows, and the two questions are
     * deliberately kept apart — callers pair this with
     * {@see RuleSelector::isProducerEnabled()} rather than reading one fact
     * that silently folds both.
     *
     * Two completions on top of the rules' own answers, both about channels a
     * rule does not declare itself:
     *
     * - a **configuration validator** declares its own channels and runs
     *   inside its producer's slot ({@see execute()}), so its levels belong to
     *   that producer and are live exactly when the instance ran at all.
     *   Measured on this tree: five such channels, all
     *   `architecture.*` at project level, produced by
     *   `architecture.layer-violation`, none of them declared by the rule
     *   class;
     * - a **classless producer** has no instance to ask. It is absent here
     *   rather than present and false, and absence is not disablement — see
     *   {@see RuleExecutionResult::$levelActivity}.
     */
    public function levelActivity(): LevelActivity
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

        foreach ($this->allRules as $rule) {
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
     * inside its producer's slot ({@see execute()}), so it is live exactly
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

    /**
     * Exclusion and selection are keyed by the producer of the **finding**, not
     * by the name of the instance that ran.
     *
     * For every static rule and every configuration validator the two are the
     * same name, so nothing moves. They part exactly on the computed-metric
     * family, where one instance publishes under seven producer names: keying
     * by the instance would apply `health.cohesion`'s `exclude_namespaces` to
     * `health.coupling`'s findings, and would let one `--disable-rule` silence
     * all seven. The granularity of {@see \Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats}
     * follows, which is a declared consequence rather than a side effect.
     *
     * The narrowing half is exact producer-name equality against `$producer`
     * — not {@see RuleSelector::isChannelEnabled()}'s selector grammar, which
     * also matches by channel code. `$producer` here is already the finding's
     * true owning producer ({@see producerOf()}), so a channel-code match
     * would only ever fire on a name collision with a *different* producer's
     * channel — a configuration validator running inside this rule's slot can
     * legitimately publish on a channel another capability owns (see the
     * class docblock this method's own docblock continues), and that other
     * producer's channel code coinciding with `$restrictToProducer` must not
     * leak its finding into a run narrowed to someone else. This mirrors
     * {@see isEnabled()}'s own narrowing, and for the same reason: one
     * contract, one comparison, not two vocabularies that can disagree.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function published(
        string $ruleName,
        array $findings,
        RuleSelection $selection,
        ?string $restrictToProducer,
    ): array {
        $kept = [];

        foreach ($findings as $finding) {
            $producer = $this->producerOf($finding, $ruleName);

            if (!$this->exclusions->keeps($producer, $finding)) {
                continue;
            }

            $enabled = $this->ruleSelector->isChannelEnabled(
                $producer,
                $finding->channel(),
                $finding->level(),
                $selection->only,
                $selection->disabled,
            ) && ($restrictToProducer === null || $producer === $restrictToProducer);

            if ($enabled) {
                $kept[] = $finding;
            }
        }

        return $kept;
    }

    /**
     * Falls back to the name of the instance that produced the finding when no
     * identity view is installed — the behaviour of every caller that builds
     * this executor directly, and the behaviour of the whole system before one
     * class began publishing under more than one producer name.
     */
    private function producerOf(Finding $finding, string $ruleName): string
    {
        return $this->channelIdentity?->producerOf($finding->channel()->code) ?? $ruleName;
    }

    /**
     * Runs one validator and refuses a finding on a channel it did not
     * declare.
     *
     * This is where the discriminator is enforced rather than merely
     * described. "Configuration error" is now a consequence of the producing
     * type, so a validator emitting on a rule-declared channel would publish a
     * finding classified as ordinary debt from a producer that has no
     * thresholds, no baseline story and no suppression — the exact confusion
     * the split exists to remove. It is a wiring error, so it ends the run.
     *
     * @return list<Finding>
     */
    private function validate(ConfigurationValidatorInterface $validator, AnalysisContext $context): array
    {
        $declared = $validator::channelDeclarations();
        $findings = $validator->validate($context);

        foreach ($findings as $finding) {
            $key = $finding->channel()->code;

            if (isset($declared[$key])) {
                continue;
            }

            throw new LogicException(\sprintf(
                'Configuration validator %s emitted a finding on channel "%s", which it does not declare.'
                . ' A validator\'s findings are configuration errors by virtue of its type; emitting on a'
                . ' channel declared elsewhere would publish one under the wrong classification.',
                $validator::class,
                $key,
            ));
        }

        return $findings;
    }

    /**
     * @param iterable<ConfigurationValidatorInterface> $validators
     *
     * @return array<string, list<ConfigurationValidatorInterface>>
     */
    private static function groupByProducer(iterable $validators): array
    {
        $grouped = [];

        foreach ($validators as $validator) {
            $grouped[$validator::producerRuleName()][] = $validator;
        }

        return $grouped;
    }

    public function allRules(): array
    {
        return $this->allProducers($this->ruleOptionsRegistry->selection());
    }

    /**
     * Every producer this container knows, rule instances and classless
     * declarations alike, each carrying whether `$selection` leaves it enabled.
     *
     * @return list<RuleMetadata>
     */
    private function allProducers(RuleSelection $selection): array
    {
        $producers = [];

        foreach ($this->allRules as $rule) {
            $producers[] = new RuleMetadata(
                name: $rule->getName(),
                optionsClass: $rule::getOptionsClass(),
                description: $rule->getDescription(),
                aliases: CliAliasReader::read($rule::class),
                active: $this->isEnabled($rule->getName(), $selection),
            );
        }

        foreach ($this->classlessProducers as $producer) {
            $producers[] = new RuleMetadata(
                name: $producer->name,
                optionsClass: $producer->optionsClass,
                description: $producer->description,
                aliases: $producer->aliases,
                active: $this->isEnabled($producer->name, $selection),
            );
        }

        return $producers;
    }

    /**
     * Whether the run's selection leaves this producer enabled, and — when the
     * caller narrowed the execution — whether the narrowing leaves it enabled
     * too.
     *
     * The two are asked in series rather than merged into one selection
     * because they are different claims: the first is what the run was
     * configured to do, the second is what this one execution was asked about.
     * Merging them would let a narrowing to `X` re-enable an `X` the
     * configuration had disabled, which is exactly what the narrowing must not
     * do.
     *
     * The narrowing checks exact producer-name equality, not
     * {@see RuleSelector::isProducerEnabled()}: that selector grammar also
     * matches a producer by any channel it publishes, which would enable a
     * second producer whose channel code happens to equal
     * `$restrictToProducer`. The contract this narrows to is one exact name,
     * not the broader "selector" vocabulary `--only-rule` accepts.
     */
    private function isEnabled(
        string $producerRuleName,
        RuleSelection $selection,
        ?string $restrictToProducer = null,
    ): bool {
        if (!$this->ruleSelector->isProducerEnabled($producerRuleName, $selection->only, $selection->disabled)) {
            return false;
        }

        return $restrictToProducer === null || $producerRuleName === $restrictToProducer;
    }

    /**
     * The instances to run.
     *
     * An instance runs when its own producer is enabled **or** when one of the
     * classless producers it hosts is: `--only-rule health.typing` names a
     * producer that has no analysis of its own, and dropping its host would
     * silence exactly the findings that were asked for. The per-finding filter
     * in {@see published()} then removes whatever the selection did not name.
     *
     * @return list<RuleInterface>
     */
    private function activeRuleInstances(RuleSelection $selection, ?string $restrictToProducer): array
    {
        return array_values(array_filter(
            $this->allRules,
            fn(RuleInterface $rule): bool => $this->isEnabled($rule->getName(), $selection, $restrictToProducer)
                || $this->hostsAnEnabledProducer($rule->getName(), $selection, $restrictToProducer),
        ));
    }

    private function hostsAnEnabledProducer(
        string $hostRuleName,
        RuleSelection $selection,
        ?string $restrictToProducer = null,
    ): bool {
        foreach ($this->classlessProducers as $producer) {
            if (
                $producer->hostRuleName === $hostRuleName
                && $this->isEnabled($producer->name, $selection, $restrictToProducer)
            ) {
                return true;
            }
        }

        return false;
    }
}
