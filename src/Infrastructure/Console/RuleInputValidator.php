<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
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

        // The universe's own name set, not a second one read off rule classes:
        // six producers of the computed-metric family have no class to read a
        // NAME off, so a class-derived list would refuse selectors and option
        // owners the run itself accepts.
        $producers = $channels->ruleNames();

        // The run's own universe, not the container's: a computed-metric
        // channel declares its levels only once configuration has resolved, and
        // this is the instance the run then reports through.
        $this->ruleSelector->useDeclaredLevels($channels);

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
     * A level narrows a selector to one level of the channels it names, and a
     * level a channel does not report at is refused by
     * {@see ChannelLevelAddressing} — the one place that judgement is made,
     * shared with the inline directives.
     *
     * The retired `rule#code` spelling is refused **first**, before the pair is
     * judged at all: its `#` half is not a name, so the pair question would
     * report that half as unparseable and never reach the spelling that was
     * retired. Every seam reading this grammar refuses the two in that order.
     *
     * @param list<string> $selectors
     * @param list<string> $producers
     */
    private function validateSelectionSelectors(
        array $selectors,
        array $producers,
        ChannelUniverseInterface $channels,
    ): void {
        $levels = new ChannelLevelAddressing($channels);

        foreach ($selectors as $selector) {
            if (FindingChannel::isRetiredPairSpelling($selector)) {
                throw ConfigurationRefusal::aboutResolvedInput(
                    \sprintf(
                        'Rule selector "%s" is written in the retired channel-pair form. %s',
                        $selector,
                        FindingChannel::retiredPairAdvice($selector),
                    ),
                );
            }

            // The subject goes into the seam so it composes one diagnostic;
            // prefixing the returned sentence here would duplicate context.
            $pairProblem = $levels->problemWith($selector, \sprintf('Rule selector "%s"', $selector));

            if ($pairProblem !== null) {
                throw ConfigurationRefusal::aboutResolvedInput($pairProblem);
            }

            if ($selector === '' || !$this->ruleSelector->matchesKnownIn($selector, $producers, $channels)) {
                $levelled = ChannelLevelSelector::carriesLevelSeparator($selector);

                throw ConfigurationRefusal::aboutResolvedInput(
                    \sprintf(
                        'Rule selector "%s" does not match any registered producer or channel%s.%s',
                        $selector,
                        $levelled ? ' at that level' : '',
                        $levelled ? '' : $this->groupSpellingHint($selector, $producers, $channels),
                    ),
                );
            }
        }
    }

    /**
     * A bare group name is the likeliest miss: the selector grammar dropped it
     * in favour of `NAME.*`, and the two spellings differ by two characters.
     * Offered only when the starred spelling would actually select something,
     * so the hint never points at a second refusal.
     *
     * @param list<string> $producers
     */
    private function groupSpellingHint(string $selector, array $producers, ChannelUniverseInterface $channels): string
    {
        if ($selector === '' || str_ends_with($selector, '.*')) {
            return '';
        }

        $starred = $selector . '.*';

        return $this->ruleSelector->matchesKnownIn($starred, $producers, $channels)
            ? \sprintf(' A bare prefix is not a group: write "%s" to select every rule under "%s".', $starred, $selector)
            : '';
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
            $owners[] = self::ownerOfWellFormedPair($option);
        }

        foreach (array_unique($owners) as $owner) {
            if ($owner === '' || FindingChannel::isRetiredPairSpelling($owner) || !$this->ruleSelector->matchesKnownProducer($owner, $producers)) {
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
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--workers',
                \sprintf('Invalid value "%s" for --workers. Expected a non-negative integer.', $workers),
            );
        }
    }
}
