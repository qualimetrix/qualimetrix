<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Input\InputInterface;

/** Fail-closed validation for all rule selectors accepted by CLI adapters. */
final readonly class RuleInputValidator
{
    public function __construct(
        private RuleRegistryInterface $ruleRegistry,
        private RuleSelector $ruleSelector,
    ) {}

    public function validate(TransitionalResolvedConfiguration $resolved, InputInterface $input): void
    {
        $producers = array_map(
            static fn(string $class): string => $class::NAME,
            $this->ruleRegistry->getClasses(),
        );

        foreach ([...$resolved->ruleSelection->only, ...$resolved->ruleSelection->disabled] as $selector) {
            if ($selector === '' || !$this->ruleSelector->matchesKnown($selector, $producers)) {
                throw new InvalidArgumentException(\sprintf(
                    'Rule selector "%s" does not match any registered producer, group, or channel.',
                    $selector,
                ));
            }
        }

        $owners = array_keys($resolved->ruleOptions);
        /** @var list<string> $cliOptions */
        $cliOptions = $input->hasOption('rule-opt') ? $input->getOption('rule-opt') : [];
        foreach ($cliOptions as $option) {
            $colon = strpos($option, ':');
            if ($colon === false || $colon === 0) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid --rule-opt "%s". Expected RULE:OPTION=VALUE.',
                    $option,
                ));
            }
            $owners[] = substr($option, 0, $colon);
        }

        foreach (array_unique($owners) as $owner) {
            if ($owner === '' || str_contains($owner, '#') || !$this->ruleSelector->matchesKnownProducer($owner, $producers)) {
                throw new InvalidArgumentException(\sprintf(
                    'Rule option owner "%s" does not match any registered producer rule.',
                    $owner,
                ));
            }
        }
    }
}
