<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
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
    /** Both spellings the option is accepted under; see `RuleOptionsFactory`. */
    private const array CHANNEL_EXCLUSION_KEYS = ['exclude_namespace_channels', 'excludeNamespaceChannels'];

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
        $this->validateSelectionSelectors([...$selection->only, ...$selection->disabled], $producers, $channels);
        $this->validateOptionOwners($configuration, $input, $producers);
        $this->validateChannelExclusionSelectors($configuration, $channels);

        return $channels;
    }

    /**
     * `only_rules` / `disabled_rules` and their CLI twins: each addresses a
     * producer or one of its channels, and nothing else.
     *
     * @param list<string> $selectors
     * @param list<string> $producers
     */
    private function validateSelectionSelectors(
        array $selectors,
        array $producers,
        RuleChannelRegistryInterface $channels,
    ): void {
        foreach ($selectors as $selector) {
            if ($selector === '' || !$this->ruleSelector->matchesKnownIn($selector, $producers, $channels)) {
                throw new InvalidArgumentException(\sprintf(
                    'Rule selector "%s" does not match any registered producer, group, or channel.',
                    $selector,
                ));
            }
        }
    }

    /**
     * `rules:` keys and `--rule-opt RULE:...`: options are applied by exact
     * key, so an owner is one producer name — never a group, never a channel.
     *
     * @param list<string> $producers
     */
    private function validateOptionOwners(
        FindingConfiguration $configuration,
        InputInterface $input,
        array $producers,
    ): void {
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
    }

    /**
     * `exclude_namespace_channels` is keyed by a channel selector, and until
     * now any spelling was accepted: a key addressing nothing silently
     * excluded nothing, which looks exactly like an exclusion that works.
     *
     * Validated here, against the universe of the configuration being
     * validated, for the same reason the selection selectors are: a
     * configuration surface fails before analysis starts, loudly, and its
     * failure is not something a baseline or a suppression can absorb.
     */
    private function validateChannelExclusionSelectors(
        FindingConfiguration $configuration,
        ChannelUniverseInterface $channels,
    ): void {
        foreach ($configuration->ruleOptions->rules as $ruleName => $options) {
            if (!\is_array($options)) {
                continue;
            }

            foreach (self::CHANNEL_EXCLUSION_KEYS as $key) {
                /** @var mixed $map */
                $map = $options[$key] ?? null;
                if (!\is_array($map)) {
                    continue;
                }

                foreach (array_keys($map) as $selector) {
                    self::assertAddressesAChannel($channels, (string) $ruleName, (string) $selector);
                }
            }
        }
    }

    private static function assertAddressesAChannel(
        ChannelUniverseInterface $channels,
        string $ruleName,
        string $selector,
    ): void {
        $parsed = NameSelector::tryParse($selector);

        if ($parsed !== null && $channels->expand($parsed) !== []) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            'Option "exclude_namespace_channels" for rule "%s" is keyed by "%s", which addresses no channel.'
            . ' Write an exact channel name, or "X.*" for the channels below it.',
            $ruleName,
            $selector,
        ));
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
