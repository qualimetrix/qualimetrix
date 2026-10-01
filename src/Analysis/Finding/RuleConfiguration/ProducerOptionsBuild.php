<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\RuleSuppression;
use Qualimetrix\Analysis\Finding\Contract\Selection\StatedEnablement;

/** Constructs one registered producer's options and suppression together. */
final readonly class ProducerOptionsBuild
{
    private RuleSuppressionSelectorDecoder $suppressionSelectors;

    public function __construct()
    {
        $this->suppressionSelectors = new RuleSuppressionSelectorDecoder();
    }

    /** @return array{RuleOptionsInterface, RuleSuppression} */
    public function build(FindingConfiguration $configuration, StatedEnablement $stated, RuleMetadata $producer): array
    {
        $ruleName = $producer->name;
        $optionsClass = self::optionsClass($producer);
        $values = (new ResolvedRuleOptionValues($configuration->document, $ruleName))
            ->withEnabled($stated->isEnabled($ruleName));
        try {
            $suppression = $this->suppression($ruleName, $values);
            return [$optionsClass::fromResolved($values), $suppression];
        } catch (RuleOptionRefusal $refusal) {
            self::refuseValue($configuration, $ruleName, $refusal);
        }
    }

    /** @return class-string<RuleOptionsInterface> */
    private static function optionsClass(RuleMetadata $producer): string
    {
        $optionsClass = $producer->optionsClass;
        if (!class_exists($optionsClass)) {
            throw new InvalidArgumentException(\sprintf('Options class %s does not exist', $optionsClass));
        }
        if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
            throw new InvalidArgumentException(\sprintf('Options class %s must implement %s', $optionsClass, RuleOptionsInterface::class));
        }
        return $optionsClass;
    }

    private function suppression(string $ruleName, ResolvedRuleOptionValues $values): RuleSuppression
    {
        return new RuleSuppression(
            paths: $this->suppressionSelectors->optionalPaths($ruleName, 'suppress_paths', $values->list(FrameworkOptionKeys::PATHS)),
            namespaces: $this->suppressionSelectors->optionalNamespaces($ruleName, 'suppress_namespaces', $values->list(FrameworkOptionKeys::NAMESPACES)),
            namespaceChannels: $this->suppressionSelectors->channels($ruleName, $values->map(FrameworkOptionKeys::NAMESPACE_CHANNELS)),
        );
    }

    private static function refuseValue(FindingConfiguration $configuration, string $ruleName, RuleOptionRefusal $refusal): never
    {
        if ($refusal->bandValues !== []) {
            ThresholdBandRefusal::refuse($configuration, $ruleName, $refusal);
        }
        $node = $configuration->document->get('rules', $ruleName, ...$refusal->optionPath)
            ?? throw new LogicException('A rule option refusal must address a written document node.', previous: $refusal);
        $node->refuse($refusal->getMessage());
    }
}
