<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;

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
        $validator->validate($input, new FindingConfiguration(
            new RuleOptionsDocument([]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        ), new ResolvedComputedMetricDefinitions([]));

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
            $configuration = new FindingConfiguration(
                new RuleOptionsDocument([]),
                new FindingCliOverrides([]),
                new RuleSelection(only: [$selector]),
            );

            $snapshot = $validator->validate(new ArrayInput([], new InputDefinition()), $configuration, $definitions);
            self::assertSame(
                ['health.complexity'],
                array_map(static fn($channel): string => $channel->code, $snapshot->channelsProducedBy('health.complexity')),
            );
        }
    }

    /**
     * `exclude_namespace_channels` is keyed by a channel selector, and a key
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
                formulas: ['class' => 'm["complexity.ccn.avg"]'],
                description: 'Complexity health',
                levels: [SymbolLevel::Class_],
                inverted: true,
            ),
        ]);

        $accepted = new FindingConfiguration(
            new RuleOptionsDocument([
                'health.complexity' => ['exclude_namespace_channels' => ['health.complexity' => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );
        $validator->validate(new ArrayInput([], new InputDefinition()), $accepted, $definitions);

        $rejected = new FindingConfiguration(
            new RuleOptionsDocument([
                'health.complexity' => ['exclude_namespace_channels' => ['health' => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is keyed by "health", which addresses no channel');
        $validator->validate(new ArrayInput([], new InputDefinition()), $rejected, $definitions);
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

        $accepted = new FindingConfiguration(
            new RuleOptionsDocument([
                'health.complexity' => [
                    'exclude_namespace_channels' => ['health.complexity:namespace' => ['App\\Legacy']],
                ],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );
        $validator->validate(new ArrayInput([], new InputDefinition()), $accepted, $definitions);

        $rejected = new FindingConfiguration(
            new RuleOptionsDocument([
                'health.complexity' => ['exclude_namespace_channels' => ['health.complexity:file' => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it does not report at level "file"');
        $validator->validate(new ArrayInput([], new InputDefinition()), $rejected, $definitions);
    }

    /**
     * The option only ever removes namespace aggregates, so a key narrowed to a
     * level the channel *does* report at is still a filter that can never fire.
     */
    #[Test]
    public function itRejectsAChannelExclusionKeyNarrowedToALevelTheOptionNeverAsksAbout(): void
    {
        $validator = $this->validatorForComputedHealth();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('removes namespace aggregates only');
        $validator->validate(
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
        $validator = $this->validatorForComputedHealth();
        $configuration = new FindingConfiguration(
            new RuleOptionsDocument([]),
            new FindingCliOverrides([]),
            new RuleSelection(disabled: ['health.complexity#health.complexity:class']),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is written in the retired channel-pair form');
        $validator->validate(
            new ArrayInput([], new InputDefinition()),
            $configuration,
            self::healthComplexityDefinitions(),
        );
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

        $validator->validate(
            new ArrayInput([], new InputDefinition()),
            self::channelExclusion('health.complexity'),
            self::healthComplexityDefinitions(),
        );

        $this->expectNotToPerformAssertions();
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Write "health.complexity"');
        $validator->validate(
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

        $configuration = new FindingConfiguration(
            new RuleOptionsDocument([
                'health.complexity' => ['exclude_namespace_channels' => ['health.complexity' => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('addresses no channel');
        $validator->validate(
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

        $configuration = new FindingConfiguration(
            new RuleOptionsDocument([
                LcomRule::NAME => ['exclude_namespace_channels' => ['health.complexity' => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('none of them produced by "cohesion.lcom"');
        $validator->validate(
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
        $selector = new RuleSelector($static);
        $validator = new RuleInputValidator($rules, $selector, new FindingConfigurationResolver(), $static);
        $disabled = ['health.complexity:class'];

        $snapshot = $validator->validate(
            new ArrayInput([], new InputDefinition()),
            new FindingConfiguration(
                new RuleOptionsDocument([]),
                new FindingCliOverrides([]),
                new RuleSelection(disabled: $disabled),
            ),
            self::healthComplexityDefinitions(),
        );
        $validator->replaceChannels($snapshot);

        self::assertFalse($selector->isProducerEnabled('health.complexity', [], $disabled));
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

    private static function channelExclusion(string $key): FindingConfiguration
    {
        return new FindingConfiguration(
            new RuleOptionsDocument([
                'health.complexity' => ['exclude_namespace_channels' => [$key => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );
    }

    private function validator(RuleRegistryInterface $rules): RuleInputValidator
    {
        $static = self::universe($rules);

        return new RuleInputValidator(
            $rules,
            new RuleSelector($static),
            new FindingConfigurationResolver(),
            $static,
        );
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
