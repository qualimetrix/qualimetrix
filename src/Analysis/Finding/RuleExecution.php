<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
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

    /** @var array<string, int> */
    private array $namespaceExclusionCounts = [];

    /** @var array<string, int> */
    private array $pathExclusionCounts = [];

    /** @var list<Finding> */
    private array $excludedFindings = [];

    private bool $capturesExcluded = false;

    /**
     * @param iterable<RuleInterface> $rules All registered rules
     * @param iterable<ConfigurationValidatorInterface> $configurationValidators Every registered validator; each
     *                                                                           runs in its producer rule's slot, see {@see execute()}
     */
    public function __construct(
        iterable $rules,
        private readonly ProfilerInterface $profiler,
        private readonly RuleConfigurationInterface $ruleOptionsRegistry,
        ?RuleSelector $ruleSelector = null,
        iterable $configurationValidators = [],
    ) {
        $this->allRules = $rules instanceof Traversable
            ? iterator_to_array($rules, false)
            : array_values($rules);
        $this->validatorsByProducer = self::groupByProducer($configurationValidators);
        $this->ruleSelector = $ruleSelector ?? new RuleSelector(new InMemoryRuleChannelRegistry());
    }

    public function execute(AnalysisContext $context): array
    {
        $findings = [];
        $profiler = $this->profiler;

        $this->beginExclusionAccounting();

        $selection = $this->ruleOptionsRegistry->selection();
        foreach ($this->activeRuleInstances($selection) as $rule) {
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

            $ruleFindings = $this->withoutExcludedNamespaces($ruleName, $ruleFindings);
            $ruleFindings = $this->withoutExcludedPaths($ruleName, $ruleFindings);

            // Apply rule selectors while the producing rule is still known.
            // A finding's channel ruleName may differ from its producer's
            // NAME, so flattening first would discard information required by
            // producer-level selectors such as `computed.health`.
            $ruleFindings = array_values(array_filter(
                $ruleFindings,
                fn(Finding $finding): bool => $this->ruleSelector->isChannelEnabled(
                    $ruleName,
                    $finding->channel(),
                    $selection->only,
                    $selection->disabled,
                ),
            ));

            $findings = [...$findings, ...$ruleFindings];
        }

        return $findings;
    }

    /**
     * Per-run exclusion tallies live in fields only between
     * {@see beginExclusionAccounting()} and the end of {@see execute()}; the
     * two filters below are where a finding is counted out, and keeping the
     * counters here is what lets each of them stay one short loop rather than
     * a loop plus two out-parameters.
     */
    private function beginExclusionAccounting(): void
    {
        $this->namespaceExclusionCounts = [];
        $this->pathExclusionCounts = [];
        $this->excludedFindings = [];
        $this->capturesExcluded = $this->ruleOptionsRegistry->capturesExcludedFindings();
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function withoutExcludedNamespaces(string $ruleName, array $findings): array
    {
        $kept = [];

        foreach ($findings as $finding) {
            if (!$this->isNamespaceExcluded($ruleName, $finding)) {
                $kept[] = $finding;

                continue;
            }

            $this->namespaceExclusionCounts[$ruleName] = ($this->namespaceExclusionCounts[$ruleName] ?? 0) + 1;
            $this->recordExcluded($finding);
        }

        return $kept;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function withoutExcludedPaths(string $ruleName, array $findings): array
    {
        $kept = [];

        foreach ($findings as $finding) {
            $file = $finding->location->file;

            if ($file === null || !$this->ruleOptionsRegistry->isPathExcluded($ruleName, $file)) {
                $kept[] = $finding;

                continue;
            }

            $this->pathExclusionCounts[$ruleName] = ($this->pathExclusionCounts[$ruleName] ?? 0) + 1;
            $this->recordExcluded($finding);
        }

        return $kept;
    }

    private function recordExcluded(Finding $finding): void
    {
        if ($this->capturesExcluded) {
            $this->excludedFindings[] = $finding;
        }
    }

    private function isNamespaceExcluded(string $ruleName, Finding $finding): bool
    {
        // Occurrence-style rules attach a file symbol path (namespace null) to
        // their findings; the declaring namespace lives on the subject, so
        // fall back to it the same way NamespaceExclusionFilter does.
        $namespace = $finding->symbolPath->namespace
            ?? $finding->subject->toSymbolPath()->namespace
            ?? null;
        if ($namespace === null || $namespace === '') {
            return false;
        }

        if ($this->ruleOptionsRegistry->isNamespaceExcluded($ruleName, $namespace)) {
            return true;
        }

        return $finding->symbolPath->getType()->value === 'namespace'
            && $this->ruleOptionsRegistry->isNamespaceChannelExcluded($ruleName, $finding->channel(), $namespace);
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

    public function exclusionStats(): RuleExclusionStats
    {
        return new RuleExclusionStats(
            namespaceExclusionsByRule: $this->namespaceExclusionCounts,
            pathExclusionsByRule: $this->pathExclusionCounts,
            excludedFindings: $this->excludedFindings,
        );
    }

    public function activeRules(RuleSelection $selection): array
    {
        return array_map(
            fn(RuleInterface $rule): RuleMetadata => $this->metadata($rule, true),
            $this->activeRuleInstances($selection),
        );
    }

    public function allRules(): array
    {
        $selection = $this->ruleOptionsRegistry->selection();

        return array_map(
            fn(RuleInterface $rule): RuleMetadata => $this->metadata(
                $rule,
                $this->ruleSelector->isProducerEnabled($rule->getName(), $selection->only, $selection->disabled),
            ),
            $this->allRules,
        );
    }

    public function totalRuleCount(): int
    {
        return \count($this->allRules);
    }

    /** @return list<RuleInterface> */
    private function activeRuleInstances(RuleSelection $selection): array
    {
        return array_values(array_filter(
            $this->allRules,
            fn(RuleInterface $rule): bool => $this->ruleSelector->isProducerEnabled(
                $rule->getName(),
                $selection->only,
                $selection->disabled,
            ),
        ));
    }

    /**
     * @qmx-ignore code-smell.boolean-argument -- The private metadata selector maps one rule into the same immutable view for either the complete or active registry; the boolean is an internal two-state query flag, and splitting it would duplicate the exact mapping.
     */
    private function metadata(RuleInterface $rule, bool $active): RuleMetadata
    {
        return new RuleMetadata(
            name: $rule->getName(),
            optionsClass: $rule::getOptionsClass(),
            category: $rule->getCategory(),
            description: $rule->getDescription(),
            aliases: CliAliasReader::read($rule::class),
            active: $active,
        );
    }
}
