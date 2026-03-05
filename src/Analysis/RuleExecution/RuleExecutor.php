<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\RuleExecution;

use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleInterface;
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

    /**
     * @param iterable<RuleInterface> $rules All registered rules
     */
    public function __construct(
        iterable $rules,
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {
        $this->allRules = $rules instanceof Traversable
            ? iterator_to_array($rules, false)
            : array_values($rules);
    }

    public function execute(AnalysisContext $context): array
    {
        $violations = [];
        $config = $this->configurationProvider->getConfiguration();

        foreach ($this->getActiveRules() as $rule) {
            if ($rule instanceof HierarchicalRuleInterface) {
                // For hierarchical rules, execute all levels
                foreach ($rule->getSupportedLevels() as $level) {
                    $levelViolations = $rule->analyzeLevel($level, $context);
                    $violations = [...$violations, ...$levelViolations];
                }
            } else {
                $ruleViolations = $rule->analyze($context);
                $violations = [...$violations, ...$ruleViolations];
            }
        }

        // Filter violations by violationCode
        return array_values(array_filter(
            $violations,
            static fn($v) => $config->isViolationCodeEnabled($v->violationCode),
        ));
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

    public function getTotalRulesCount(): int
    {
        return \count($this->allRules);
    }
}
