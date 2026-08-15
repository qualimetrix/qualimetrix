<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;

#[CoversClass(RuleNameReader::class)]
final class RuleNameReaderTest extends TestCase
{
    #[Test]
    public function itReadsTheNameConstantWithoutInstantiation(): void
    {
        self::assertSame(ComplexityRule::NAME, RuleNameReader::read(ComplexityRule::class));
    }

    #[Test]
    public function itRejectsRulesWithoutANameConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a string NAME constant');

        RuleNameReader::read(FixtureRuleWithoutName::class);
    }

    #[Test]
    public function itRejectsAClassThatCannotBeLoaded(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('does not exist or cannot be autoloaded');

        RuleNameReader::read('Qualimetrix\Analysis\Evidence\Complexity\NoSuchRule');
    }

    #[Test]
    public function itRejectsANonStringNameConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a string NAME constant');

        RuleNameReader::read(FixtureRuleWithNonStringName::class);
    }
}

/**
 * @internal
 */
final class FixtureRuleWithoutName {}

/**
 * @internal
 */
final class FixtureRuleWithNonStringName
{
    public const int NAME = 42;
}
