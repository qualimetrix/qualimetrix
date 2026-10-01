<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRuleOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(RuleInputValidator::class)]
final class RuleInputValidatorTest extends TestCase
{
    #[Test]
    public function itIgnoresAnAbsentWorkersOption(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([]);
        $validator = $this->validator($rules);

        $input = new ArrayInput([], new InputDefinition());
        $this->validate($validator, $input, [], new ResolvedComputedMetricDefinitions([]));

        self::assertFalse($input->hasOption('workers'));
    }

    #[Test]
    public function itValidatesEveryComputedChannelSelectorFromTheSameResolvedDefinitions(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class]);
        $validator = $this->validator($rules);
        $definitions = new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'm["complexity.ccn.avg"]'],
                description: 'Complexity health',
                levels: [SymbolLevel::Class_],
                inverted: true,
            ),
        ]);

        foreach (['health.complexity', 'health.*'] as $selector) {
            $snapshot = $this->validate($validator, new ArrayInput([], new InputDefinition()), [], $definitions);
            $document = ResolvedOptionsFixture::document([
                ['source' => 'config', 'values' => ['only_rules' => [$selector]]],
            ], AbsolutePath::fromString('/project'), []);
            $stated = (new RuleEnablementResolver())->decide($document->resolved(), $snapshot);
            self::assertSame([$selector], $stated->filter()?->selectors);
            self::assertSame(
                ['health.complexity'],
                array_map(static fn($channel): string => $channel->code, $snapshot->channelsProducedBy('health.complexity')),
            );
        }
    }

    /**
     * `suppress_namespace_channels` is keyed by a channel selector, and a key
     * that addresses nothing used to exclude nothing while looking exactly
     * like an exclusion that works.
     *
     * Both directions are asserted from one configuration, so the case cannot
     * pass by rejecting everything.
     */
    #[Test]
    public function itRejectsAChannelExclusionKeyThatAddressesNoChannel(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class]);
        $validator = $this->validator($rules);
        $definitions = new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'm["complexity.ccn.avg"]', 'namespace' => 'm["complexity.ccn.avg"]'],
                description: 'Complexity health',
                levels: [SymbolLevel::Class_, SymbolLevel::Namespace_],
                inverted: true,
            ),
        ]);

        $accepted = [
            'health.complexity' => ['suppress_namespace_channels' => ['health.complexity' => [['subtree' => 'App\\Legacy']]]],
        ];
        $this->validate($validator, new ArrayInput([], new InputDefinition()), $accepted, $definitions);

        $rejected = [
            'health.complexity' => ['suppress_namespace_channels' => ['health' => [['subtree' => 'App\\Legacy']]]],
        ];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('keyed by "health", addresses no channel');
        $this->validate($validator, new ArrayInput([], new InputDefinition()), $rejected, $definitions);
    }

    /**
     * The second seam of the one refusal point for an impossible
     * `channel:level` pair — the exclusion key. Both directions from one
     * configuration, so the case cannot pass by rejecting everything.
     */
    #[Test]
    public function itRejectsAChannelExclusionKeyNamingALevelItsChannelDoesNotReportAt(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class]);
        $validator = $this->validator($rules);
        $definitions = self::healthComplexityDefinitions(SymbolLevel::Class_, SymbolLevel::Namespace_);

        $accepted = [
            'health.complexity' => [
                'suppress_namespace_channels' => ['health.complexity:namespace' => [['subtree' => 'App\\Legacy']]],
            ],
        ];
        $this->validate($validator, new ArrayInput([], new InputDefinition()), $accepted, $definitions);

        $rejected = [
            'health.complexity' => ['suppress_namespace_channels' => ['health.complexity:file' => [['subtree' => 'App\\Legacy']]]],
        ];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('it does not report at level "file"');
        $this->validate($validator, new ArrayInput([], new InputDefinition()), $rejected, $definitions);
    }

    /**
     * The option only ever removes namespace aggregates, so a key narrowed to a
     * level the channel *does* report at is still a filter that can never fire.
     */
    #[Test]
    public function itRejectsAChannelExclusionKeyNarrowedToALevelTheOptionNeverAsksAbout(): void
    {
        $validator = $this->validatorForComputedHealth();

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('removes namespace aggregates only');
        $this->validate(
            $validator,
            new ArrayInput([], new InputDefinition()),
            self::channelExclusion('health.complexity:class'),
            self::healthComplexityDefinitions(),
        );
    }

    /**
     * A selector carrying both the retired `#` pair and a level is answered
     * about the pair: the level question can only report the `#` half as
     * unparseable, which says nothing about the spelling that was retired.
     */
    #[Test]
    public function itRefusesTheRetiredPairBeforeJudgingTheLevel(): void
    {
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['disabled_rules' => ['health.complexity#health.complexity:class']]],
        ], AbsolutePath::fromString('/project'), [
            new RuleMetadata('health.complexity', ComputedMetricRuleOptions::class, '', [], false),
        ]);
        $channels = new ChannelUniverse([], [], ['health.complexity' => false], self::healthComplexityDefinitions());

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('is written in the retired channel-pair form');
        (new RuleEnablementResolver())->decide($document->resolved(), $channels);
    }

    /**
     * The documented grammar is one grammar everywhere, and this option is the
     * one whose key *is* a channel, so it must accept the channel by its own
     * name.
     */
    #[Test]
    public function itAcceptsAChannelExclusionKeyNamingTheChannel(): void
    {
        $validator = $this->validatorForComputedHealth();

        $snapshot = $this->validate(
            $validator,
            new ArrayInput([], new InputDefinition()),
            self::channelExclusion('health.complexity'),
            self::healthComplexityDefinitions(SymbolLevel::Class_, SymbolLevel::Namespace_),
        );

        self::assertSame([SymbolLevel::Class_, SymbolLevel::Namespace_], $snapshot->levelsOf('health.complexity'));
        self::assertSame(['health.complexity'], array_map(static fn($channel): string => $channel->code, $snapshot->channelsProducedBy('health.complexity')));
    }

    /**
     * A key left in the retired `rule#code` spelling is refused **by name**,
     * with the name to write instead. Silence here is the failure mode the
     * refusal exists for: the key would parse as nothing, exclude nothing, and
     * say nothing.
     */
    #[Test]
    public function itRefusesAChannelExclusionKeyInTheRetiredPairForm(): void
    {
        $validator = $this->validatorForComputedHealth();

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Write "health.complexity"');
        $this->validate(
            $validator,
            new ArrayInput([], new InputDefinition()),
            self::channelExclusion('computed.health#health.complexity'),
            self::healthComplexityDefinitions(),
        );
    }

    /**
     * A computed-metric channel exists only while its definition does, so a
     * key naming one that configuration no longer defines addresses nothing.
     * The owner check is a different branch and is covered by
     * {@see itRefusesAKeyAddressingAnotherRulesChannel}.
     */
    #[Test]
    public function itRefusesAKeyNamingAComputedChannelNoLongerDefined(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class]);
        $validator = $this->validator($rules);

        $configuration = [
            'health.complexity' => ['suppress_namespace_channels' => ['health.complexity' => [['subtree' => 'App\\Legacy']]]],
        ];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('addresses no channel');
        $this->validate(
            $validator,
            new ArrayInput([], new InputDefinition()),
            $configuration,
            new ResolvedComputedMetricDefinitions([]),
        );
    }

    /**
     * A key that addresses a channel of *another* rule is refused differently
     * from one that addresses no channel at all: the author spelled a real
     * name, just under the wrong owner, and the message says so.
     */
    #[Test]
    public function itRefusesAKeyAddressingAnotherRulesChannel(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class, LcomRule::class]);
        $validator = $this->validator($rules);

        $configuration = [
            LcomRule::NAME => ['suppress_namespace_channels' => ['health.complexity' => [['subtree' => 'App\\Legacy']]]],
        ];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('addresses none of the channels of "cohesion.lcom"');
        $this->validate(
            $validator,
            new ArrayInput([], new InputDefinition()),
            $configuration,
            self::healthComplexityDefinitions(),
        );
    }

    /**
     * A producer whose every channel is silenced at every level it declares
     * stops, instead of running its collection phase so that all of its output
     * can be filtered away. The levels are only knowable from the run's own
     * universe, so this is asserted through the preflight that resolves it.
     */
    #[Test]
    public function itStopsAProducerWhoseEveryDeclaredLevelTheRunDisabled(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class]);
        $static = self::universe($rules);
        $validator = new RuleInputValidator(
            $rules,
            $static,
            new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild(self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class)),
            self::createStub(ComputedMetricConfiguratorInterface::class),
            new RuleEnablementResolver(),
        );
        $disabled = ['health.complexity:class'];

        $snapshot = $this->validate(
            $validator,
            new ArrayInput([], new InputDefinition()),
            [],
            self::healthComplexityDefinitions(),
        );
        $metadata = array_values(array_map(
            static fn(string $name): RuleMetadata => new RuleMetadata($name, ComputedMetricRuleOptions::class, 'Computed health', [], false),
            $snapshot->ruleNames(),
        ));
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['disabled_rules' => $disabled]],
        ], AbsolutePath::fromString('/project'), $metadata);
        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata, channels: $snapshot);

        self::assertFalse($ready->enablement?->runs('health.complexity'));
    }

    /**
     * The pair form is owner, option and value: a pair carrying no `=` assigns
     * nothing, and the run it silently accepted applied none of it.
     */
    #[Test]
    public function itRefusesARuleOptionPairThatAssignsNoValue(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([LcomRule::class]);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Invalid --rule-opt "cohesion.lcom:exclude_methods". Expected RULE:OPTION=VALUE.');
        $this->validate(
            $this->validator($rules),
            self::ruleOptionInput('cohesion.lcom:exclude_methods'),
            [],
            new ResolvedComputedMetricDefinitions([]),
        );
    }

    #[Test]
    public function itAcceptsARuleOptionPairThatCarriesAValue(): void
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([LcomRule::class]);
        $input = self::ruleOptionInput('cohesion.lcom:exclude_methods=getName');

        $this->validate(
            $this->validator($rules),
            $input,
            [],
            new ResolvedComputedMetricDefinitions([]),
        );

        self::assertSame(['cohesion.lcom:exclude_methods=getName'], $input->getOption('rule-opt'));
    }

    private static function ruleOptionInput(string $pair): ArrayInput
    {
        return new ArrayInput(
            ['--rule-opt' => [$pair]],
            new InputDefinition([
                new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            ]),
        );
    }

    private function validatorForComputedHealth(): RuleInputValidator
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class]);

        return $this->validator($rules);
    }

    private static function healthComplexityDefinitions(
        SymbolLevel $level = SymbolLevel::Class_,
        SymbolLevel ...$moreLevels,
    ): ResolvedComputedMetricDefinitions {
        return new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health',
                levels: array_values([$level, ...$moreLevels]),
                inverted: true,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private static function channelExclusion(string $key): array
    {
        return [
            'health.complexity' => ['suppress_namespace_channels' => [$key => [['subtree' => 'App\\Legacy']]]],
        ];
    }

    private function validator(RuleRegistryInterface $rules): RuleInputValidator
    {
        $static = self::universe($rules);

        return new RuleInputValidator(
            $rules,
            $static,
            new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild(self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class)),
            self::createStub(ComputedMetricConfiguratorInterface::class),
            new RuleEnablementResolver(),
        );
    }

    /** @param array<string, mixed> $rules */
    private function validate(
        RuleInputValidator $validator,
        InputInterface $input,
        array $rules,
        ResolvedComputedMetricDefinitions $definitions,
    ): ChannelUniverse {
        $names = [ComputedMetricRule::NAME, LcomRule::NAME, ...ComputedMetricChannelFamily::HEALTH_PRODUCER_RULE_NAMES];
        $channels = new ChannelUniverse([], [], array_fill_keys($names, false), $definitions);
        $execution = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create()->get(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
        \assert($execution instanceof \Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface);
        $cliWrites = (new \Qualimetrix\Infrastructure\Console\CliOptionsParser((new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory())->createFromMetadata($execution->allRules())))->pathWrites($input);
        $authored = ResolvedOptionsFixture::authoredConfiguration($rules === [] ? [] : ['rules' => $rules], $execution->allRules(), $cliWrites);
        $document = new \Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument([], AbsolutePath::fromString('/project'), $authored->document);
        $computed = self::createStub(ComputedMetricConfiguratorInterface::class);
        $computed->method('resolve')->willReturn($definitions);
        $resolved = (new RuleInputValidator(
            self::createStub(RuleRegistryInterface::class),
            $channels,
            new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsBuild($execution),
            $computed,
            new RuleEnablementResolver(),
        ))->resolve($document, $input);
        $snapshot = $validator->validate($input, $resolved, $definitions);
        \assert($snapshot instanceof ChannelUniverse);
        return $snapshot;
    }

    /**
     * The addressable names are the universe's, not the registry's: since the
     * computed-metric family split, six producers have no class to read a NAME
     * off, so a universe built without them refuses selectors the run accepts.
     * Assembled here the way ChannelDeclarationCompilerPass assembles it.
     */
    private static function universe(RuleRegistryInterface $rules): ChannelUniverse
    {
        $names = array_map(static fn(string $class): string => $class::NAME, $rules->getClasses());

        if (\in_array(ComputedMetricRule::NAME, $names, true)) {
            $names = [...$names, ...ComputedMetricChannelFamily::HEALTH_PRODUCER_RULE_NAMES];
        }

        return new ChannelUniverse([], [], array_fill_keys($names, false), new ResolvedComputedMetricDefinitions([]));
    }
}
