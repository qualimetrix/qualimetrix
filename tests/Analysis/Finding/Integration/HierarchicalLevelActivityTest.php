<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Every hierarchical rule, every level of it, switched off one at a time.
 *
 * The rules are not listed here: they are read from the container, so a sixth
 * hierarchical rule is covered the day it is registered rather than the day
 * someone remembers this file. The count is asserted beside them for the
 * reason any list is checked in both directions — a container that offered
 * none would otherwise turn this into a test that proves nothing by iterating
 * an empty set.
 *
 * Configuration goes in through {@see RuleConfigurationInterface}, and the
 * answer comes out of the real executor: a rule may take constructor
 * dependencies beyond its options, so building one by hand to ask it would be
 * testing an object the product never assembles.
 */
final class HierarchicalLevelActivityTest extends TestCase
{
    private const int HIERARCHICAL_RULES = 5;

    /**
     * @param list<string> $levels
     */
    #[Test]
    #[DataProvider('provideHierarchicalRules')]
    public function itRecordsOnlyTheLevelItsConfigurationSwitchedOff(string $rule, array $levels): void
    {
        $baseline = self::levelActivityOf([])[$rule] ?? [];

        foreach ($levels as $off) {
            $activity = self::levelActivityOf([$rule => [$off => ['enabled' => false]]])[$rule] ?? [];

            self::assertArrayHasKey($off, $activity);
            self::assertFalse($activity[$off], \sprintf('%s at %s must be recorded as not run', $rule, $off));

            foreach ($levels as $other) {
                if ($other === $off) {
                    continue;
                }

                // Not "must stay live": `complexity.npath` reports at class
                // level only when asked, so a level's default is what the
                // neighbouring switch must leave alone. Asserting liveness
                // instead would encode one rule's default as every rule's.
                self::assertSame(
                    $baseline[$other] ?? null,
                    $activity[$other] ?? null,
                    \sprintf('%s at %s must be untouched while only %s is off', $rule, $other, $off),
                );
            }
        }
    }

    /**
     * @param list<string> $levels
     */
    #[Test]
    #[DataProvider('provideHierarchicalRules')]
    public function itRecordsEveryLevelAsNotRunWhenAllOfThemAreSwitchedOff(string $rule, array $levels): void
    {
        $config = [];

        foreach ($levels as $level) {
            $config[$level] = ['enabled' => false];
        }

        $activity = self::levelActivityOf([$rule => $config])[$rule] ?? [];

        self::assertNotSame([], $activity, 'the rule must still declare where it reports');
        self::assertSame([], array_filter($activity));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideHierarchicalRules(): iterable
    {
        $container = (new ContainerFactory())->create();
        $execution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);

        $found = 0;
        $cases = [];

        foreach ($execution->allRules() as $metadata) {
            if (!is_a($metadata->optionsClass, HierarchicalRuleOptionsInterface::class, true)) {
                continue;
            }

            ++$found;
            $options = $metadata->optionsClass::fromArray([]);
            self::assertInstanceOf(HierarchicalRuleOptionsInterface::class, $options);

            $cases[$metadata->name] = [
                $metadata->name,
                array_map(static fn(SymbolLevel $level): string => $level->value, $options->getSupportedLevels()),
            ];
        }

        self::assertSame(
            self::HIERARCHICAL_RULES,
            $found,
            'the hierarchical population changed: the cases follow it, this count does not',
        );

        yield from $cases;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, array<string, bool>>
     */
    private static function levelActivityOf(array $options): array
    {
        $container = (new ContainerFactory())->create();

        $configuration = $container->get(RuleConfigurationInterface::class);
        self::assertInstanceOf(RuleConfigurationInterface::class, $configuration);
        $configuration->replace(new FindingConfiguration(
            new RuleOptionsDocument($options),
            new FindingCliOverrides([]),
            new RuleSelection(),
        ));

        $execution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);

        return $execution->levelActivity()->toMap();
    }
}
