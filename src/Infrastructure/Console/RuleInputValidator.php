<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/** Fail-closed validation for all rule selectors accepted by CLI adapters. */
final readonly class RuleInputValidator
{
    public function __construct(
        private RuleRegistryInterface $ruleRegistry,
        private RuleSelector $ruleSelector,
        private FindingConfigurationResolverInterface $findingConfigurationResolver,
        private RuleChannelSnapshotFactoryInterface $ruleChannelSnapshotFactory,
    ) {}

    public function resolve(ConfigurationDocument $document, InputInterface $input): FindingConfiguration
    {
        $parser = (new RuleOptionsParserFactory())->createFromClasses($this->ruleRegistry->getClasses());
        $cliRuleOptions = (new CliOptionsParser($parser))->parseRuleOptions($input);

        return $this->findingConfigurationResolver->resolve($document, new FindingCliOverrides($cliRuleOptions));
    }

    public function validate(
        InputInterface $input,
        FindingConfiguration $configuration,
        ResolvedComputedMetricDefinitions $definitions,
    ): RuleChannelRegistryInterface {
        $this->validateWorkers($input);
        $channels = $this->ruleChannelSnapshotFactory->snapshot($definitions);
        $producers = array_map(
            static fn(string $class): string => $class::NAME,
            $this->ruleRegistry->getClasses(),
        );

        $selection = $configuration->selection;
        foreach ([...$selection->only, ...$selection->disabled] as $selector) {
            if ($selector === '' || !$this->ruleSelector->matchesKnownIn($selector, $producers, $channels)) {
                throw new InvalidArgumentException(\sprintf(
                    'Rule selector "%s" does not match any registered producer, group, or channel.',
                    $selector,
                ));
            }
        }

        $owners = array_keys($configuration->ruleOptions->rules);
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

        return $channels;
    }

    public function replaceChannels(RuleChannelRegistryInterface $channels): void
    {
        $this->ruleSelector->replaceChannels($channels);
    }

    public function resetChannels(): void
    {
        $this->ruleSelector->resetChannels();
    }

    /** @return list<string> */
    public function configureCheckCommand(Command $command): array
    {
        return CheckCommandDefinition::addOptions($command, $this->ruleRegistry);
    }

    public function conflictingSelectionWarning(FindingConfiguration $configuration): ?string
    {
        $selection = $configuration->selection;

        return $selection->disabled !== [] && $selection->only !== []
            ? 'Warning: both --disable-rule and --only-rule are active. This may result in no rules being enabled.'
            : null;
    }

    private function validateWorkers(InputInterface $input): void
    {
        if (!$input->hasOption('workers')) {
            return;
        }

        $workers = $input->getOption('workers');
        if ($workers === null) {
            return;
        }

        if (filter_var($workers, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidArgumentException(
                \sprintf('Invalid value "%s" for --workers. Expected a non-negative integer.', $workers),
            );
        }
    }
}
