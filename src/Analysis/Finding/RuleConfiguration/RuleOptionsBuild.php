<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\OptionActivity;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ModeGatedOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleSuppression;
use Qualimetrix\Analysis\Finding\Selection\StatedEnablement;

/** Builds every producer's immutable options from the judged document. */
final readonly class RuleOptionsBuild
{
    public function __construct(
        private RuleExecutionInterface $execution,
        private RuleSuppressionSelectorDecoder $suppressionSelectors = new RuleSuppressionSelectorDecoder(),
    ) {}

    public function build(FindingConfiguration $configuration, StatedEnablement $stated): ResolvedRuleOptions
    {
        $options = [];
        $suppressions = [];
        $activity = [];
        foreach ($this->execution->allRules() as $producer) {
            $ruleName = $producer->name;
            $optionsClass = $producer->optionsClass;
            if (!class_exists($optionsClass)) {
                throw new InvalidArgumentException(\sprintf('Options class %s does not exist', $optionsClass));
            }
            if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
                throw new InvalidArgumentException(\sprintf('Options class %s must implement %s', $optionsClass, RuleOptionsInterface::class));
            }
            $values = (new ResolvedRuleOptionValues($configuration->document, $ruleName))
                ->withEnabled($stated->isEnabled($ruleName));
            try {
                $suppressions[$ruleName] = new RuleSuppression(
                    paths: $this->suppressionSelectors->optionalPaths($ruleName, 'suppress_paths', $values->list(FrameworkOptionKeys::PATHS)),
                    namespaces: $this->suppressionSelectors->optionalNamespaces($ruleName, 'suppress_namespaces', $values->list(FrameworkOptionKeys::NAMESPACES)),
                    namespaceChannels: $this->suppressionSelectors->channels($ruleName, $values->map(FrameworkOptionKeys::NAMESPACE_CHANNELS)),
                );
                $options[$ruleName] = $optionsClass::fromResolved($values);
            } catch (RuleOptionRefusal $refusal) {
                if ($refusal->bandValues !== []) {
                    self::refuseBand($configuration, $ruleName, $refusal);
                }
                $node = $configuration->document->get('rules', $ruleName, ...$refusal->optionPath)
                    ?? throw new LogicException('A rule option refusal must address a written document node.', previous: $refusal);
                $node->refuse($refusal->getMessage());
            }
        }
        foreach ($stated->decisions() as $decision) {
            $ruleName = $decision->producer;
            $level = $decision->level;
            $option = $options[$ruleName] ?? throw new LogicException('Every decided producer requires resolved options.');
            $active = $option instanceof HierarchicalRuleOptionsInterface && $level !== null
                ? $option->isLevelEnabled($level)
                : !($option instanceof ModeGatedOptionsInterface && $option->isMuted());
            $path = $option instanceof ModeGatedOptionsInterface
                ? ['rules', $ruleName, 'mode']
                : ($option instanceof HierarchicalRuleOptionsInterface && $level !== null
                    ? ['rules', $ruleName, $level->value, 'enabled'] : []);
            $node = $path === [] ? null : $configuration->document->get(...$path);
            $writes = $node instanceof ResolvedWriteHistoryInterface ? $node->writes() : [];
            $last = $writes === [] ? null : $writes[\count($writes) - 1];
            $activity[$ruleName][$level === null ? '' : $level->value] = new OptionActivity(
                $active,
                $last === null ? null : self::authoredSwitch($last['provenance'], $last['value']),
                $last['provenance'] ?? null,
            );
        }
        return new ResolvedRuleOptions($options, $suppressions, $activity);
    }

    private static function authoredSwitch(Provenance $writer, mixed $value): string
    {
        if (!\is_bool($value) && !\is_string($value)) {
            throw new LogicException('A decided rule option switch must be a scalar boolean or mode.');
        }
        $text = \is_bool($value) ? ($value ? 'true' : 'false') : $value;
        return $writer->path === null
            ? ($writer->origin->locator() ?? '--rule-opt') . '=' . $text
            : $writer->displayPath() . ': ' . $text;
    }

    private static function refuseBand(FindingConfiguration $configuration, string $producer, RuleOptionRefusal $refusal): never
    {
        $writers = [];
        $descriptions = [];
        foreach ($refusal->bandValues as $role => $half) {
            if ($half['path'] === null) {
                $descriptions[] = \sprintf('%s: default %s', $role, $half['value']);
                continue;
            }
            $node = $configuration->document->get('rules', $producer, ...$half['path']);
            if (!$node instanceof ResolvedWriteHistoryInterface) {
                throw new LogicException('A refused threshold must expose its authored writes.', previous: $refusal);
            }
            $contributors = $node->contributors();
            $winner = $contributors[\count($contributors) - 1];
            $found = false;
            foreach ($node->writes() as $write) {
                if ($write['provenance']->layerIndex === $winner->layerIndex) {
                    $winner = $write['provenance'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new LogicException('The winning threshold must occur in its authored history.', previous: $refusal);
            }
            $writers[] = $winner;
            $where = $winner->path === null
                ? $winner->origin->describe()
                : \sprintf('"%s" in %s', $winner->displayPath(), $winner->origin->describe());
            if ($winner->line !== null) {
                $where .= \sprintf(' at line %d', $winner->line);
            }
            $descriptions[] = \sprintf('%s: %s from %s', $role, $half['value'], $where);
        }
        if ($writers === []) {
            throw new LogicException('An invalid threshold band must have an authored half.', previous: $refusal);
        }
        $details = implode('; ', $descriptions);
        throw Provenance::refusalOf($writers, $refusal->getMessage() . ' ' . ucfirst($details) . '.');
    }
}
