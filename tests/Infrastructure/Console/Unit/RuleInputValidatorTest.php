<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
