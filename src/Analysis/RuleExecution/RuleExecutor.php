<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\RuleExecution;

use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleInterface;
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

    private RuleExclusionStats $lastExclusionStats;

    /**
     * @param iterable<RuleInterface> $rules All registered rules
     */
    public function __construct(
        iterable $rules,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly RuleOptionsRegistry $ruleOptionsRegistry = new RuleOptionsRegistry(),
    ) {
        $this->allRules = $rules instanceof Traversable
            ? iterator_to_array($rules, false)
            : array_values($rules);
        $this->lastExclusionStats = new RuleExclusionStats();
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

        $namespaceExclusion = $this->ruleOptionsRegistry->getExclusionProvider();
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
                if (
                    $violation->symbolPath->namespace !== null
                    && $violation->symbolPath->namespace !== ''
                    && $namespaceExclusion->isExcluded($ruleName, $violation->symbolPath->namespace)
                ) {
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

            $violations = [...$violations, ...$ruleViolations];
        }

        $this->lastExclusionStats = new RuleExclusionStats(
            namespaceExclusionsByRule: $namespaceExclusionCounts,
            pathExclusionsByRule: $pathExclusionCounts,
            excludedViolations: $excludedViolations,
        );

        // Filter violations by violationCode
        return array_values(array_filter(
            $violations,
            static fn($v) => $config->isViolationCodeEnabled($v->violationCode),
        ));
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
                static fn(RuleInterface $rule): bool => $config->isRuleEnabled($rule->getName()),
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
