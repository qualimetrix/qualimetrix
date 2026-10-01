<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\ProducerDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\SelectionTrace;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Traversable;

/**
 * Default implementation of RuleExecutionInterface.
 *
 * Executes the producers admitted by the resolved invocation snapshot and
 * publishes their findings through the same channel decisions.
 *
 * @qmx-threshold coupling.cbo warning=22 -- Ce is 18, one of them the publication
 * snapshot this executor answers with, as it answers with its level activity. The three
 * afferent edges are the dependency-injection composition that registers this class and
 * rewrites its arguments, which names it by `::class` so the architecture manifest can see
 * and bind each access. Raw CBO 21 gets one-edge headroom; the error bound stays the project's.
 * @qmx-threshold coupling.instability warning=0.86 -- The same three composition edges
 * are the only afferent ones: without them Ca is 0 and the class sits under
 * `min_afferent`. An executor is efferent by construction; Ce=18, Ca=3 is 0.857.
 */
final class RuleExecution implements RuleExecutionInterface
{
    /** @var list<array{metadata: RuleMetadata, create: Closure(): RuleInterface}> */
    private readonly array $allRules;

    private ?ResolvedRuleOptions $snapshot = null;

    /** @var array<int, RuleInterface> */
    private array $materializedRules = [];

    /** @var array<string, list<ConfigurationValidatorInterface>> */
    private array $materializedValidators = [];

    /** @var array<string, list<Closure(): ConfigurationValidatorInterface>> */
    private readonly array $validatorsByProducer;

    private readonly FindingExclusionLedger $exclusions;

    /** @var list<ProducerDeclaration> */
    private readonly array $classlessProducers;

    /**
     * @param iterable<array{metadata: RuleMetadata, create: Closure(): RuleInterface}> $rules Ordered deferred rules
     * @param iterable<array{producer: string, create: Closure(): ConfigurationValidatorInterface}> $configurationValidators Every deferred validator; each
     *                                                                                                                       runs in its producer rule's slot, see {@see execute()}
     * @param iterable<ProducerDeclaration> $classlessProducers Producers a capability owns without a rule
     *                                                          class of their own; they are part of every
     *                                                          "registered rule" answer and of none of the
     *                                                          execution ones, because there is nothing to run
     */
    public function __construct(
        iterable $rules,
        private readonly ProfilerInterface $profiler,
        private readonly RuleConfigurationInterface $ruleOptionsRegistry,
        iterable $configurationValidators = [],
        iterable $classlessProducers = [],
    ) {
        $this->classlessProducers = $classlessProducers instanceof Traversable
            ? iterator_to_array($classlessProducers, false)
            : array_values($classlessProducers);
        $this->allRules = $rules instanceof Traversable
            ? iterator_to_array($rules, false)
            : array_values($rules);
        $this->validatorsByProducer = self::groupByProducer($configurationValidators);
        $this->exclusions = new FindingExclusionLedger($ruleOptionsRegistry);
    }

    public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult
    {
        $this->readySnapshot();
        $produced = [];
        $published = [];
        $profiler = $this->profiler;

        $this->exclusions->begin();

        $enablement = $this->readyEnablement();
        $removed = [];
        foreach ($this->activeRuleInstances($enablement, $restrictToProducer) as $rule) {
            $ruleName = $rule->getName();

            // One span, and the validators run inside it: a configuration
            // validator occupies its producer's slot in the execution order,
            // which is what keeps the position of its findings — and therefore
            // the order of every report that does not sort — exactly where it
            // was while the diagnostics lived in the rule class.
            $spanName = 'rule.' . $ruleName;
            $profiler->start($spanName, 'rules');
            $ruleFindings = $rule->analyze($context);
            foreach ($this->validatorsFor($ruleName) as $validator) {
                $ruleFindings = [...$ruleFindings, ...$this->validate($validator, $context)];
            }
            $profiler->stop($spanName);

            $produced = [...$produced, ...$ruleFindings];
            $published = [...$published, ...$this->published($ruleName, $ruleFindings, $enablement, $restrictToProducer, $removed)];
        }

        return new RuleExecutionResult(
            $produced,
            $published,
            $this->exclusions->stats(),
            $this->levelActivity(),
            new SelectionTrace($removed, $enablement->notRun()),
        );
    }

    /**
     * The channel-selection half of {@see published()}, offered to the one
     * caller that assembles findings after {@see execute()} has returned.
     *
     * **Why the exclusion ledger is not applied with it.** A ledger lives for
     * one `execute()` call: `begin()` opens it and `stats()` reads it, and that
     * read is already frozen into the {@see RuleExecutionResult} this call
     * returns. Anything excluded here would therefore be removed from the
     * report while every account of the removal — the per-producer counters,
     * `--show-suppressed`'s retained findings and their attributions — stayed
     * at the value taken before this method ran. Channel selection is
     * idempotent, so it can be asked twice; the ledger cannot. The execution
     * result records selection removals made during {@see execute()}.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public function publishable(array $findings): array
    {
        $enablement = $this->readyEnablement();
        $kept = [];

        foreach ($findings as $finding) {
            $producer = $this->producerOf($finding, $finding->ruleName);

            $this->assertAddressedProducer($finding);
            if ($enablement->publishes($finding->channel(), $finding->level(), $finding->addressedProducer)) {
                $kept[] = $finding;
            }
        }

        return $kept;
    }

    public function publication(): ChannelPublication
    {
        return new ChannelPublication($this->readyEnablement());
    }

    public function levelActivity(): LevelActivity
    {
        $this->readySnapshot();
        return $this->readyEnablement()->levelActivity();
    }

    /**
     * Exclusion and selection are keyed by the producer of the **finding**, not
     * by the name of the instance that ran.
     *
     * For every static rule and every configuration validator the two are the
     * same name, so nothing moves. They part exactly on the computed-metric
     * family, where one instance publishes under seven producer names: keying
     * by the instance would apply `health.cohesion`'s `suppress_namespaces` to
     * `health.coupling`'s findings, and would let one `--disable-rule` silence
     * all seven. The granularity of {@see \Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats}
     * follows, which is a declared consequence rather than a side effect.
     *
     * The narrowing half is exact producer-name equality against `$producer`.
     * `$producer` here is already the finding's
     * true owning producer ({@see producerOf()}), so a channel-code match
     * would only ever fire on a name collision with a *different* producer's
     * channel — a configuration validator running inside this rule's slot can
     * legitimately publish on a channel another capability owns (see the
     * class docblock this method's own docblock continues), and that other
     * producer's channel code coinciding with `$restrictToProducer` must not
     * leak its finding into a run narrowed to someone else. This mirrors
     * the exact producer restriction used for execution.
     *
     * @param list<Finding> $findings
     * @param list<array{finding: Finding, suppressor: string}> $removed
     *
     * @return list<Finding>
     */
    private function published(
        string $ruleName,
        array $findings,
        RuleEnablement $enablement,
        ?string $restrictToProducer,
        array &$removed,
    ): array {
        $kept = [];

        foreach ($findings as $finding) {
            $producer = $this->producerOf($finding, $ruleName);
            $this->assertAddressedProducer($finding);

            if (!$this->exclusions->keeps($producer, $finding)) {
                continue;
            }

            $enabled = $enablement->publishes($finding->channel(), $finding->level(), $finding->addressedProducer)
                && ($restrictToProducer === null || $producer === $restrictToProducer);

            if ($enabled) {
                $kept[] = $finding;
            } else {
                $removed[] = [
                    'finding' => $finding,
                    'suppressor' => $restrictToProducer !== null && $producer !== $restrictToProducer
                        ? \sprintf('restricted to producer "%s"', $restrictToProducer)
                        : $enablement->selectionSuppressor($finding->channel(), $finding->level(), $finding->addressedProducer),
                ];
            }
        }

        return $kept;
    }

    /** The final universe identifies hosted channels; undeclared codes retain the instance name. */
    private function producerOf(Finding $finding, string $ruleName): string
    {
        return $this->ruleOptionsRegistry->channelUniverse()->producerOf($finding->channel()->code) ?? $ruleName;
    }

    private function assertAddressedProducer(Finding $finding): void
    {
        $channels = $this->ruleOptionsRegistry->channelUniverse();
        $role = $channels->declarationFor($finding->channel())?->selectionRole;
        if ($finding->addressedProducer === null) {
            return;
        }
        if ($role !== ChannelSelectionRole::FollowsAddressedRule || !$channels->hasRule($finding->addressedProducer)) {
            throw new LogicException(\sprintf('Channel "%s" cannot address producer "%s".', $finding->channel()->code, $finding->addressedProducer));
        }
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
     * @param iterable<array{producer: string, create: Closure(): ConfigurationValidatorInterface}> $validators
     *
     * @return array<string, list<Closure(): ConfigurationValidatorInterface>>
     */
    private static function groupByProducer(iterable $validators): array
    {
        $grouped = [];

        foreach ($validators as $validator) {
            $grouped[$validator['producer']][] = $validator['create'];
        }

        return $grouped;
    }

    public function allRules(): array
    {
        return $this->allProducers($this->ruleOptionsRegistry->enablement());
    }

    /**
     * Every producer this container knows, static rule metadata and classless
     * declarations alike, each carrying the final invocation decision.
     *
     * @return list<RuleMetadata>
     */
    private function allProducers(?RuleEnablement $enablement): array
    {
        $producers = [];

        foreach ($this->allRules as $lookup) {
            $metadata = $lookup['metadata'];
            $producers[] = new RuleMetadata(
                name: $metadata->name,
                optionsClass: $metadata->optionsClass,
                description: $metadata->description,
                aliases: $metadata->aliases,
                active: $enablement?->runs($metadata->name) ?? true,
            );
        }

        foreach ($this->classlessProducers as $producer) {
            $producers[] = new RuleMetadata(
                name: $producer->name,
                optionsClass: $producer->optionsClass,
                description: $producer->description,
                aliases: $producer->aliases,
                active: $enablement?->runs($producer->name) ?? true,
            );
        }

        return $producers;
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
    private function activeRuleInstances(RuleEnablement $enablement, ?string $restrictToProducer): array
    {
        $this->readySnapshot();
        $active = [];
        foreach ($this->allRules as $index => $lookup) {
            $name = $lookup['metadata']->name;
            $ownEnabled = $enablement->runs($name) && ($restrictToProducer === null || $name === $restrictToProducer);
            if ($ownEnabled || $this->hostsAnEnabledProducer($name, $enablement, $restrictToProducer)) {
                $active[] = $this->ruleAt($index);
            }
        }
        return $active;
    }

    private function hostsAnEnabledProducer(
        string $hostRuleName,
        RuleEnablement $enablement,
        ?string $restrictToProducer = null,
    ): bool {
        foreach ($this->classlessProducers as $producer) {
            if (
                $producer->hostRuleName === $hostRuleName
                && $enablement->runs($producer->name)
                && ($restrictToProducer === null || $producer->name === $restrictToProducer)
            ) {
                return true;
            }
        }

        return false;
    }
    private function readyEnablement(): RuleEnablement
    {
        return $this->ruleOptionsRegistry->enablement()
            ?? throw new LogicException('Rule enablement is unavailable before analysis preflight.');
    }
    private function readySnapshot(): ResolvedRuleOptions
    {
        $snapshot = $this->ruleOptionsRegistry->resolvedOptions();
        if ($this->snapshot !== $snapshot) {
            $this->materializedRules = [];
            $this->materializedValidators = [];
            $this->snapshot = $snapshot;
        }
        return $snapshot;
    }

    private function ruleAt(int $index): RuleInterface
    {
        $this->readySnapshot();
        return $this->materializedRules[$index] ??= ($this->allRules[$index]['create'])();
    }

    /** @return list<ConfigurationValidatorInterface> */
    private function validatorsFor(string $producer): array
    {
        $this->readySnapshot();
        if (!isset($this->materializedValidators[$producer])) {
            $this->materializedValidators[$producer] = [];
            foreach ($this->validatorsByProducer[$producer] ?? [] as $create) {
                $this->materializedValidators[$producer][] = $create();
            }
        }
        return $this->materializedValidators[$producer];
    }

}
