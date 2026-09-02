<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;

/**
 * Configuration says "off" in two spellings, and the rule must read both.
 *
 * This used to be asserted against the audit, which re-derived enablement from
 * the merged configuration itself. It moved here with the answer: a rule
 * reports what its own options decided, so the two spellings are a fact about
 * reading options and belong beside that reading rather than beside a consumer
 * that once duplicated it.
 */
final class LevelActivityReadsBothDisablingSpellingsTest extends TestCase
{
    /**
     * @param array<string, mixed>|false $configured
     */
    #[Test]
    #[DataProvider('provideDisablingSpellings')]
    public function itReportsEveryDeclaredLevelAsNotRun(array|false $configured): void
    {
        $levels = self::levelsOfAGotoRuleConfiguredWith($configured);

        self::assertNotSame([], $levels, 'the rule must still declare where it reports');
        self::assertSame([], array_filter($levels), 'no level of a disabled rule may be recorded as having run');
    }

    /**
     * @return iterable<string, array{array<string, mixed>|false}>
     */
    public static function provideDisablingSpellings(): iterable
    {
        yield 'the explicit key' => [['enabled' => false]];

        yield 'the scalar shorthand' => [false];
    }

    #[Test]
    public function itReportsTheLevelAsRunWhenNothingSwitchedItOff(): void
    {
        self::assertNotSame([], array_filter(self::levelsOfAGotoRuleConfiguredWith([])));
    }

    /**
     * @param array<string, mixed>|false $configured
     *
     * @return array<string, bool>
     */
    private static function levelsOfAGotoRuleConfiguredWith(array|false $configured): array
    {
        $registry = new RuleOptionsRegistry();
        $registry->setConfigFileOptions($configured === [] ? [] : [GotoRule::NAME => $configured]);

        $options = (new RuleOptionsFactory($registry))->create(GotoRule::NAME, GotoRule::getOptionsClass());

        return (new GotoRule($options))->levelActivity()[GotoRule::NAME] ?? [];
    }
}
