<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\Cyclomatic\ComplexityRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Rule\AbstractRule;

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

    #[Test]
    public function itRejectsAnInheritedPlaceholderName(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare its own NAME constant');

        RuleNameReader::read(FixtureRuleWithInheritedName::class);
    }

    #[Test]
    public function itRejectsAnEmptyNameUsedByTheSharedGetter(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must declare a public non-empty string NAME constant');

        RuleNameReader::read(FixtureRuleWithEmptyName::class);
    }

    #[Test]
    public function itKeepsInheritedMetadataForAnOverriddenGetter(): void
    {
        self::assertSame('fixture.overridden-name', RuleNameReader::read(FixtureRuleWithOverriddenNameGetter::class));
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

/** @internal */
final class FixtureRuleWithInheritedName extends AbstractRule
{
    public static function getOptionsClass(): string
    {
        return RuleOptionsInterface::class;
    }

    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
}

/** @internal */
final class FixtureRuleWithEmptyName extends AbstractRule
{
    public const string NAME = '';

    public static function getOptionsClass(): string
    {
        return RuleOptionsInterface::class;
    }

    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
}

/** @internal */
abstract class FixtureRuleWithNameMetadata extends AbstractRule
{
    public const string NAME = 'fixture.overridden-name';
}

/** @internal */
final class FixtureRuleWithOverriddenNameGetter extends FixtureRuleWithNameMetadata
{
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getOptionsClass(): string
    {
        return RuleOptionsInterface::class;
    }

    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
}
