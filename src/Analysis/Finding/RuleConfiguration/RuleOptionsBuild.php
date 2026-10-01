<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleSuppression;

/** Builds every producer's immutable options from the judged document. */
final readonly class RuleOptionsBuild
{
    public function __construct(
        private RuleExecutionInterface $execution,
        private RuleSuppressionSelectorDecoder $suppressionSelectors = new RuleSuppressionSelectorDecoder(),
    ) {}

    public function build(FindingConfiguration $configuration): ResolvedRuleOptions
    {
        $options = [];
        $suppressions = [];
        foreach ($this->execution->allRules() as $producer) {
            $ruleName = $producer->name;
            $optionsClass = $producer->optionsClass;
            if (!class_exists($optionsClass)) {
                throw new InvalidArgumentException(\sprintf('Options class %s does not exist', $optionsClass));
            }
            if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
                throw new InvalidArgumentException(\sprintf('Options class %s must implement %s', $optionsClass, RuleOptionsInterface::class));
            }
            $values = new ResolvedRuleOptionValues($configuration->document, $ruleName);
            try {
                $suppressions[$ruleName] = new RuleSuppression(
                    paths: $this->suppressionSelectors->optionalPaths($ruleName, 'suppress_paths', $values->list(FrameworkOptionKeys::PATHS)),
                    namespaces: $this->suppressionSelectors->optionalNamespaces($ruleName, 'suppress_namespaces', $values->list(FrameworkOptionKeys::NAMESPACES)),
                    namespaceChannels: $this->suppressionSelectors->channels($ruleName, $values->map(FrameworkOptionKeys::NAMESPACE_CHANNELS)),
                );
                $options[$ruleName] = $optionsClass::fromResolved($values);
            } catch (RuleOptionRefusal $refusal) {
                $node = $configuration->document->get('rules', $ruleName, ...$refusal->optionPath)
                    ?? throw new LogicException('A rule option refusal must address a written document node.', previous: $refusal);
                $node->refuse($refusal->getMessage());
            }
        }
        return new ResolvedRuleOptions($options, $suppressions);
    }
}
