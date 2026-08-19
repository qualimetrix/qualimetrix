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
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Core\Symbol\SymbolType;
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
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health',
                levels: [SymbolType::Class_],
                inverted: true,
            ),
        ]);

        foreach (['computed.health', 'health.complexity', 'computed.health#health.complexity'] as $selector) {
            $configuration = new FindingConfiguration(
                new RuleOptionsDocument([]),
                new FindingCliOverrides([]),
                new RuleSelection(only: [$selector]),
            );

            $snapshot = $validator->validate(new ArrayInput([], new InputDefinition()), $configuration, $definitions);
            self::assertSame(
                ['computed.health#health.complexity'],
                array_map(static fn($channel): string => $channel->toKey(), $snapshot->channelsProducedBy(ComputedMetricRule::NAME)),
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
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health',
                levels: [SymbolType::Class_],
                inverted: true,
            ),
        ]);

        $accepted = new FindingConfiguration(
            new RuleOptionsDocument([
                'computed.health' => ['exclude_namespace_channels' => ['health.complexity' => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );
        $validator->validate(new ArrayInput([], new InputDefinition()), $accepted, $definitions);

        $rejected = new FindingConfiguration(
            new RuleOptionsDocument([
                'computed.health' => ['exclude_namespace_channels' => ['health' => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is keyed by "health", which addresses no channel');
        $validator->validate(new ArrayInput([], new InputDefinition()), $rejected, $definitions);
    }

    /**
     * The documented grammar is one grammar everywhere, and this option is the
     * one whose key *is* a channel, so it must accept the channel spelled in
     * full. The pair used to parse as a name containing a `#`, address nothing,
     * and be refused with advice that contradicted the documentation.
     */
    #[Test]
    public function itAcceptsAChannelExclusionKeyWrittenAsTheExplicitPair(): void
    {
        $validator = $this->validatorForComputedHealth();

        $validator->validate(
            new ArrayInput([], new InputDefinition()),
            self::channelExclusion('computed.health#health.complexity'),
            self::healthComplexityDefinitions(),
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function itNamesTheRuleHalfWhenOnlyTheRuleHalfIsWrong(): void
    {
        $validator = $this->validatorForComputedHealth();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the rule half is wrong, "health.complexity" is spelled computed.health#health.complexity',
        );
        $validator->validate(
            new ArrayInput([], new InputDefinition()),
            self::channelExclusion('coupling.cbo#health.complexity'),
            self::healthComplexityDefinitions(),
        );
    }

    #[Test]
    public function itRefusesAGroupInsideTheExplicitPair(): void
    {
        $validator = $this->validatorForComputedHealth();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('takes exactly two exact halves and no "*" in either');
        $validator->validate(
            new ArrayInput([], new InputDefinition()),
            self::channelExclusion('computed.health#health.*'),
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
                'computed.health' => ['exclude_namespace_channels' => ['health.complexity' => ['App\\Legacy']]],
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
        $this->expectExceptionMessage('none of them produced by "design.lcom"');
        $validator->validate(
            new ArrayInput([], new InputDefinition()),
            $configuration,
            self::healthComplexityDefinitions(),
        );
    }

    private function validatorForComputedHealth(): RuleInputValidator
    {
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([ComputedMetricRule::class]);

        return $this->validator($rules);
    }

    private static function healthComplexityDefinitions(): ResolvedComputedMetricDefinitions
    {
        return new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health',
                levels: [SymbolType::Class_],
                inverted: true,
            ),
        ]);
    }

    private static function channelExclusion(string $key): FindingConfiguration
    {
        return new FindingConfiguration(
            new RuleOptionsDocument([
                'computed.health' => ['exclude_namespace_channels' => [$key => ['App\\Legacy']]],
            ]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );
    }

    private function validator(RuleRegistryInterface $rules): RuleInputValidator
    {
        $static = new ChannelUniverse([], [], [], ComputedMetricRule::NAME, new ResolvedComputedMetricDefinitions([]));

        return new RuleInputValidator(
            $rules,
            new RuleSelector($static),
            new FindingConfigurationResolver(),
            $static,
        );
    }
}
