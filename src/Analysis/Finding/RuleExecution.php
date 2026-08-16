<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Traversable;

/**
 * Default implementation of RuleExecutionInterface.
 *
 * Filters rules at runtime based on configuration (disabled_rules, only_rules)
 * and executes only active rules. Filters individual violations by violationCode.
 */
final class RuleExecution implements RuleExecutionInterface
{
    /** @var list<RuleInterface> */
    private readonly array $allRules;

    private readonly RuleSelector $ruleSelector;

    private RuleExclusionStats $lastExclusionStats;

    /**
     * @param iterable<RuleInterface> $rules All registered rules
     */
    public function __construct(
        iterable $rules,
        private readonly ProfilerInterface $profiler,
        private readonly RuleConfigurationInterface $ruleOptionsRegistry,
        ?RuleSelector $ruleSelector = null,
    ) {
        $this->allRules = $rules instanceof Traversable
            ? iterator_to_array($rules, false)
            : array_values($rules);
        $this->lastExclusionStats = new RuleExclusionStats();
        $this->ruleSelector = $ruleSelector ?? new RuleSelector(new InMemoryRuleChannelRegistry());
    }

    public function execute(AnalysisContext $context): array
    {
        $violations = [];
        $profiler = $this->profiler;

        /** @var array<string, int> $namespaceExclusionCounts */
        $namespaceExclusionCounts = [];
        /** @var array<string, int> $pathExclusionCounts */
        $pathExclusionCounts = [];
        /** @var list<Violation> $excludedViolations */
        $excludedViolations = [];

        $captureExcludedViolations = $this->ruleOptionsRegistry->capturesExcludedViolations();

        $selection = $this->ruleOptionsRegistry->selection();
        foreach ($this->activeRuleInstances($selection) as $rule) {
            $spanName = 'rule.' . $rule->getName();
            $profiler->start($spanName, 'rules');
            $ruleViolations = $rule->analyze($context);
            $profiler->stop($spanName);

            $ruleName = $rule->getName();

            // Filter violations from excluded namespaces (per-rule)
            $kept = [];
            foreach ($ruleViolations as $violation) {
                if ($this->isNamespaceExcluded($ruleName, $violation)) {
                    $namespaceExclusionCounts[$ruleName] = ($namespaceExclusionCounts[$ruleName] ?? 0) + 1;
                    if ($captureExcludedViolations) {
                        $excludedViolations[] = $violation;
                    }

                    continue;
                }

                $kept[] = $violation;
            }
            $ruleViolations = $kept;

            // Filter violations from excluded paths (per-rule)
            $kept = [];
            foreach ($ruleViolations as $violation) {
                if (
                    $violation->location->file !== null
                    && $this->ruleOptionsRegistry->isPathExcluded($ruleName, $violation->location->file)
                ) {
                    $pathExclusionCounts[$ruleName] = ($pathExclusionCounts[$ruleName] ?? 0) + 1;
                    if ($captureExcludedViolations) {
                        $excludedViolations[] = $violation;
                    }

                    continue;
                }

                $kept[] = $violation;
            }
            $ruleViolations = $kept;

            // Apply rule selectors while the producing rule is still known.
            // A violation's channel ruleName may differ from its producer's
            // NAME, so flattening first would discard information required by
            // producer-level selectors such as `computed.health`.
            $ruleViolations = array_values(array_filter(
                $ruleViolations,
                fn(Violation $violation): bool => $this->ruleSelector->isChannelEnabled(
                    $ruleName,
                    $violation->channel(),
                    $selection->only,
                    $selection->disabled,
                ),
            ));

            $violations = [...$violations, ...$ruleViolations];
        }

        $this->lastExclusionStats = new RuleExclusionStats(
            namespaceExclusionsByRule: $namespaceExclusionCounts,
            pathExclusionsByRule: $pathExclusionCounts,
            excludedViolations: $excludedViolations,
        );

        return $violations;
    }

    private function isNamespaceExcluded(string $ruleName, Violation $violation): bool
    {
        // Occurrence-style rules attach a file symbol path (namespace null) to
        // their violations; the declaring namespace lives on the subject, so
        // fall back to it the same way NamespaceExclusionFilter does.
        $namespace = $violation->symbolPath->namespace
            ?? $violation->subject->toSymbolPath()->namespace
            ?? null;
        if ($namespace === null || $namespace === '') {
            return false;
        }

        if ($this->ruleOptionsRegistry->isNamespaceExcluded($ruleName, $namespace)) {
            return true;
        }

        return $violation->symbolPath->getType()->value === 'namespace'
            && $this->ruleOptionsRegistry->isNamespaceChannelExcluded($ruleName, $violation->violationCode, $namespace);
    }

    public function exclusionStats(): RuleExclusionStats
    {
        return $this->lastExclusionStats;
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
