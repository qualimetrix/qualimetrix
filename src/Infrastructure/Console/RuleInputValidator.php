<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/** Fail-closed validation for all rule selectors accepted by CLI adapters. */
final readonly class RuleInputValidator
{
    public function __construct(
        private RuleRegistryInterface $ruleRegistry,
        private RuleChannelSnapshotFactoryInterface $ruleChannelSnapshotFactory,
        private RuleOptionsBuild $optionsBuild,
        private ComputedMetricConfiguratorInterface $computedMetricConfigurator,
        private RuleEnablementResolver $enablementResolver,
    ) {}

    public function resolve(ConfigurationDocument $document, InputInterface $input): FindingConfiguration
    {
        $configuration = FindingConfiguration::fromDocument($document);
        $channels = $this->ruleChannelSnapshotFactory->snapshot($this->computedMetricConfigurator->resolve($document));
        $stated = $this->enablementResolver->decide($configuration->document, $channels);
        $configuration = $configuration->withChannelUniverse($channels);
        $options = $this->optionsBuild->build($configuration, $stated);
        return $configuration->withResolvedOptions($options)->withEnablement($this->enablementResolver->conclude($stated, $options));
    }

    public function validate(
        InputInterface $input,
        FindingConfiguration $configuration,
        ResolvedComputedMetricDefinitions $definitions,
    ): RuleChannelRegistryInterface {
        $this->validateWorkers($input);
        $channels = $configuration->channels
            ?? throw new LogicException('Rule channels must be decided before validation.');

        // The universe's own name set, not a second one read off rule classes:
        // six producers of the computed-metric family have no class to read a
        // NAME off, so a class-derived list would refuse selectors and option
        // owners the run itself accepts.
        $producers = $channels->ruleNames();

        // The run's own universe, not the container's: a computed-metric
        // channel declares its levels only once configuration has resolved, and
        // this is the instance the run then reports through.

        $this->validateOptionOwners($configuration, $input, $producers);
        $this->validateChannelExclusionSelectors($configuration, $channels);

        return $channels;
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
        foreach (CommandLineSpelling::options($input, 'rule-opt') as $option) {
            $owners[] = self::ownerOfWellFormedPair($option);
        }

        foreach (array_unique($owners) as $owner) {
            if ($owner === '' || FindingChannel::isRetiredPairSpelling($owner) || !\in_array($owner, $producers, true)) {
                // Owners are merged from both `rules:`/presets and `--rule-opt`
                // (`array_unique` above), so which source named this one is no
                // longer recoverable — the same reasoning as
                // `RuleOptionsFactory:367-369` for the sibling "unknown owner" refusal.
                throw ConfigurationRefusal::aboutResolvedInput(
                    \sprintf('Rule option owner "%s" does not match any registered producer rule.', $owner),
                );
            }
        }
    }

    /**
     * The owner half of one `--rule-opt` pair, refusing anything that is not a
     * pair. The value half is as much part of the form as the owner half: a
     * pair without `=` names an option and assigns it nothing, and the parser
     * has no value to apply, so accepting it silently drops the whole flag.
     */
    private static function ownerOfWellFormedPair(string $option): string
    {
        $colon = strpos($option, ':');

        if ($colon === false || $colon === 0 || strpos($option, '=', $colon) === false) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--rule-opt',
                \sprintf('Invalid --rule-opt "%s". Expected RULE:OPTION=VALUE.', $option),
            );
        }

        return substr($option, 0, $colon);
    }

    /**
     * Which keys get checked, and when. What makes one of them wrong is
     * {@see ChannelExclusionKeyValidator}.
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
        $keys = new ChannelExclusionKeyValidator($channels);

        foreach ($configuration->ruleOptions->rules as $ruleName => $options) {
            if (!\is_array($options)) {
                continue;
            }

            // Asked of the one reader of a producer's raw options rather than
            // subscripted here. This method used to hold both spellings of the
            // key itself, which was a second enumeration — and the guard that
            // keeps `ConfiguredSuppression` the only reader could not see it,
            // because the literals sat in a constant while the subscript was a
            // variable.
            foreach (array_keys(ConfiguredSuppression::rawNamespaceChannels($options)) as $selector) {
                $keys->assertAddressesAProducedChannel((string) $ruleName, (string) $selector);
            }
        }
    }

    /** @return list<string> */
    public function configureCheckCommand(Command $command): array
    {
        return CheckCommandDefinition::addOptions($command, $this->ruleRegistry);
    }

    private function validateWorkers(InputInterface $input): void
    {
        $workers = CommandLineSpelling::option($input, 'workers');
        if ($workers === null) {
            return;
        }

        if (filter_var($workers, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--workers',
                \sprintf('Invalid value "%s" for --workers. Expected a non-negative integer.', $workers),
            );
        }
    }
}
