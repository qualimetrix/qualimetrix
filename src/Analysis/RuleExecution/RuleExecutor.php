<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\RuleExecution;

use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Core\Rule\RuleLevel;
use Traversable;

/**
 * Default implementation of RuleExecutorInterface.
 *
 * Filters rules at runtime based on configuration (disabled_rules, only_rules)
 * and executes only active rules. Supports hierarchical rules with level-based filtering.
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
                // For hierarchical rules, execute each enabled level
                foreach ($rule->getSupportedLevels() as $level) {
                    if ($config->isRuleLevelEnabled($rule->getName(), $level, $rule->getCategory()->value)) {
                        $levelViolations = $rule->analyzeLevel($level, $context);
                        $violations = [...$violations, ...$levelViolations];
                    }
                }
            } else {
                // For regular rules, execute normally
                $ruleViolations = $rule->analyze($context);
                $violations = [...$violations, ...$ruleViolations];
            }
        }

        return $violations;
    }

    public function getActiveRules(): array
    {
        $config = $this->configurationProvider->getConfiguration();

        return array_values(
            array_filter(
                $this->allRules,
                static function (RuleInterface $rule) use ($config): bool {
                    // For hierarchical rules, check if any level is enabled
                    if ($rule instanceof HierarchicalRuleInterface) {
                        foreach ($rule->getSupportedLevels() as $level) {
                            if ($config->isRuleLevelEnabled($rule->getName(), $level, $rule->getCategory()->value)) {
                                return true;
                            }
                        }
                        return false;
                    }

                    // For regular rules, use standard check
                    return $config->isRuleEnabled($rule->getName(), $rule->getCategory()->value);
                },
            ),
        );
    }

    /**
     * Returns active levels for a hierarchical rule.
     *
     * @return list<RuleLevel>
     */
    public function getActiveLevels(HierarchicalRuleInterface $rule): array
    {
        $config = $this->configurationProvider->getConfiguration();
        $activeLevels = [];

        foreach ($rule->getSupportedLevels() as $level) {
            if ($config->isRuleLevelEnabled($rule->getName(), $level, $rule->getCategory()->value)) {
                $activeLevels[] = $level;
            }
        }

        return $activeLevels;
    }

    public function getTotalRulesCount(): int
    {
        return \count($this->allRules);
    }
}
