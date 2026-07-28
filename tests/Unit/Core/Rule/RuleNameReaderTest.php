<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\RuleNameReader;
use Qualimetrix\Rules\Complexity\ComplexityRule;

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

        /** @phpstan-ignore argument.type (deliberately passing a rule that violates the contract) */
        RuleNameReader::read(FixtureRuleWithoutName::class);
    }

    #[Test]
    public function itRejectsAClassThatCannotBeLoaded(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('does not exist or cannot be autoloaded');

        /** @phpstan-ignore argument.type (deliberately passing an unloadable class) */
        RuleNameReader::read('Qualimetrix\Rules\NoSuchRule');
    }

    #[Test]
    public function itRejectsANonStringNameConstant(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a string NAME constant');

        /** @phpstan-ignore argument.type (deliberately passing a rule that violates the contract) */
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
