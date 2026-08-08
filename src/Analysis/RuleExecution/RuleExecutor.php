<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\RuleExecution;

use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Core\Violation\RuleExclusionCaptureHolder;
use Qualimetrix\Core\Violation\Violation;
use Traversable;

/**
 * Default implementation of RuleExecutorInterface.
 *
 * Filters rules at runtime based on configuration (disabled_rules, only_rules)
 * and executes only active rules. Filters individual violations by violationCode.
 */
final class RuleExecutor implements RuleExecutorInterface
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
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly RuleOptionsRegistry $ruleOptionsRegistry = new RuleOptionsRegistry(),
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
        $config = $this->configurationProvider->getConfiguration();
        $profiler = ProfilerHolder::get();

        /** @var array<string, int> $namespaceExclusionCounts */
        $namespaceExclusionCounts = [];
        /** @var array<string, int> $pathExclusionCounts */
        $pathExclusionCounts = [];
        /** @var list<Violation> $excludedViolations */
        $excludedViolations = [];

        $pathExclusion = $this->ruleOptionsRegistry->getPathExclusionProvider();

        // Capturing full Violation objects (as opposed to just counting them) is
        // opt-in: it exists purely so `--show-suppressed` can list them, and holding
        // them for every run regardless would waste memory on codebases with wide
        // per-rule exclusions. See RuleExclusionCaptureHolder for why this is a
        // Core-level holder rather than a constructor flag threaded from Infrastructure.
        $captureExcludedViolations = RuleExclusionCaptureHolder::isEnabled();

        foreach ($this->getActiveRules() as $rule) {
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
                    && $pathExclusion->isExcluded($ruleName, $violation->location->file)
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
                    $config->onlyRules,
                    $config->disabledRules,
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
        $namespace = $violation->symbolPath->namespace;
        if ($namespace === null || $namespace === '') {
            return false;
        }

        $provider = $this->ruleOptionsRegistry->getExclusionProvider();
        if ($provider->isExcluded($ruleName, $namespace)) {
            return true;
        }

        return $violation->symbolPath->getType()->value === 'namespace'
            && $provider->isChannelExcluded($ruleName, $violation->violationCode, $namespace);
    }

    public function getRuleExclusionStats(): RuleExclusionStats
    {
        return $this->lastExclusionStats;
    }

    public function getActiveRules(): array
    {
        $config = $this->configurationProvider->getConfiguration();

        return array_values(
            array_filter(
                $this->allRules,
                fn(RuleInterface $rule): bool => $this->ruleSelector->isProducerEnabled(
                    $rule->getName(),
                    $config->onlyRules,
                    $config->disabledRules,
                ),
            ),
        );
    }

    public function getAllRules(): array
    {
        return $this->allRules;
    }

    public function getTotalRulesCount(): int
    {
        return \count($this->allRules);
    }
}
