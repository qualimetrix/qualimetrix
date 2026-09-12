<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;

#[CoversClass(RuleOptionShape::class)]
#[CoversClass(RuleOptionValueForm::class)]
final class RuleOptionShapeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAcceptedValues')]
    public function itAcceptsAValueOfTheFormItNames(RuleOptionShape $shape, mixed $value): void
    {
        self::assertTrue($shape->matches($value));
    }

    /**
     * @return iterable<string, array{RuleOptionShape, mixed}>
     */
    public static function provideAcceptedValues(): iterable
    {
        yield 'boolean' => [RuleOptionShape::boolean(), true];
        yield 'whole number' => [RuleOptionShape::integer(), 10];
        yield 'number takes a whole one' => [RuleOptionShape::number(), 10];
        yield 'number takes a fraction' => [RuleOptionShape::number(), 0.3];
        yield 'string' => [RuleOptionShape::text(), 'all'];
        yield 'the empty string where text is named' => [RuleOptionShape::text(), ''];
        yield 'non-empty string' => [RuleOptionShape::nonEmptyText(), 'App\\'];
        yield 'list of strings' => [RuleOptionShape::listOf(RuleOptionShape::text()), ['a', 'b']];
        yield 'empty list' => [RuleOptionShape::listOf(RuleOptionShape::text()), []];
        yield 'map of lists' => [
            RuleOptionShape::mapOf(RuleOptionShape::listOf(RuleOptionShape::text())),
            ['health.cohesion' => ['App\\']],
        ];
        yield 'block' => [RuleOptionShape::block(), ['warning' => 1]];
        yield 'either, first branch' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            'App\\',
        ];
        yield 'either, second branch' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            ['App\\'],
        ];
        yield 'null where null is named' => [RuleOptionShape::integer()->orNull(), null];
    }

    #[Test]
    #[DataProvider('provideRefusedValues')]
    public function itRefusesAValueOfAnyOtherForm(RuleOptionShape $shape, mixed $value): void
    {
        self::assertFalse($shape->matches($value));
    }

    /**
     * @return iterable<string, array{RuleOptionShape, mixed}>
     */
    public static function provideRefusedValues(): iterable
    {
        yield 'a whole number is not a boolean' => [RuleOptionShape::boolean(), 1];
        yield 'a numeric string is not a whole number' => [RuleOptionShape::integer(), '10'];
        yield 'a fraction is not a whole number' => [RuleOptionShape::integer(), 10.5];
        yield 'a list is not a number' => [RuleOptionShape::number(), [1]];
        yield 'a blank string is not a non-empty one' => [RuleOptionShape::nonEmptyText(), '  '];
        yield 'a map is not a list' => [RuleOptionShape::listOf(RuleOptionShape::text()), ['a' => 'b']];
        yield 'a wrongly typed element fails its list' => [RuleOptionShape::listOf(RuleOptionShape::text()), ['a', 7]];
        yield 'a list is not a map' => [RuleOptionShape::mapOf(RuleOptionShape::text()), ['a']];
        yield 'a scalar is not a block' => [RuleOptionShape::block(), false];
        yield 'no branch of a union matches' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            7331,
        ];
        yield 'null where null is not named' => [RuleOptionShape::integer(), null];
    }

    #[Test]
    #[DataProvider('provideDescriptions')]
    public function itNamesTheFormItExpects(RuleOptionShape $shape, string $expected): void
    {
        self::assertSame($expected, $shape->describe());
    }

    /**
     * @return iterable<string, array{RuleOptionShape, string}>
     */
    public static function provideDescriptions(): iterable
    {
        yield 'boolean' => [RuleOptionShape::boolean(), 'a boolean'];
        yield 'nullable whole number' => [RuleOptionShape::integer()->orNull(), 'a whole number or null'];
        yield 'list names its elements in the plural' => [
            RuleOptionShape::listOf(RuleOptionShape::nonEmptyText()),
            'a list of non-empty strings',
        ];
        yield 'a nullable element keeps the singular, so the "or null" stays attached to one value' => [
            RuleOptionShape::listOf(RuleOptionShape::integer()->orNull()),
            'a list of a whole number or null',
        ];
        yield 'map names its value' => [
            RuleOptionShape::mapOf(RuleOptionShape::listOf(RuleOptionShape::text())),
            'a map of a list of strings',
        ];
        yield 'block' => [RuleOptionShape::block(), 'a block of options'];
        yield 'union names both branches' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            'a string or a list of strings',
        ];
    }

    #[Test]
    #[DataProvider('provideWrittenForms')]
    public function itNamesTheFormThatWasWritten(mixed $value, string $expected): void
    {
        self::assertSame($expected, RuleOptionValueForm::describeWritten($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideWrittenForms(): iterable
    {
        yield 'list' => [['a'], 'a list'];
        yield 'map' => [['a' => 'b'], 'a map'];
        yield 'string' => ['x', 'a string'];
        yield 'empty string' => ['   ', 'an empty string'];
        yield 'boolean' => [false, 'a boolean'];
        yield 'whole number' => [7331, 'a whole number'];
        yield 'fraction' => [1.9, 'a number'];
        yield 'null' => [null, 'null'];
    }

    #[Test]
    public function itRefusesAUnionOfOneForm(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least two alternatives');

        RuleOptionShape::either(RuleOptionShape::text());
    }

    #[Test]
    public function itAcceptsOnlyTheWordsAClosedSetNames(): void
    {
        $shape = RuleOptionShape::oneOf('info', 'warning', 'error');

        self::assertTrue($shape->matches('warning'));
        self::assertFalse($shape->matches('warnin'));
        self::assertFalse($shape->matches(''));
        self::assertFalse($shape->matches(7331));
        self::assertFalse($shape->matches(['warning']));
    }

    #[Test]
    public function itMatchesAClosedSetWithoutRegardToLetterCase(): void
    {
        $shape = RuleOptionShape::oneOf('info', 'warning', 'error');

        self::assertTrue($shape->matches('WARNING'));
        self::assertTrue($shape->matches('Error'));
    }

    #[Test]
    public function itNamesEveryWordOfAClosedSetInTheRefusal(): void
    {
        self::assertSame(
            'one of "info", "warning", "error"',
            RuleOptionShape::oneOf('info', 'warning', 'error')->describe(),
        );
        self::assertSame(
            'one of "all", "application" or null',
            RuleOptionShape::oneOf('all', 'application')->orNull()->describe(),
        );
    }

    #[Test]
    public function itKeepsTheWordsWhenTheClosedSetIsMadeNullable(): void
    {
        $shape = RuleOptionShape::oneOf('all', 'application')->orNull();

        self::assertTrue($shape->matches(null));
        self::assertTrue($shape->matches('application'));
        self::assertFalse($shape->matches('applicaton'));
    }

    #[Test]
    public function itNamesTheWordThatWasWrittenWhenAClosedSetRefuses(): void
    {
        $shape = RuleOptionShape::oneOf('info', 'warning', 'error');

        self::assertSame('"warnin"', $shape->describeWritten('warnin'));
        self::assertSame('a whole number', $shape->describeWritten(7331));
        self::assertSame('an empty string', $shape->describeWritten(''));
    }

    #[Test]
    public function itNamesAWrittenValueByItsFormForEveryShapeButAClosedSet(): void
    {
        self::assertSame('a string', RuleOptionShape::text()->describeWritten('warnin'));
        self::assertSame('a list', RuleOptionShape::listOf(RuleOptionShape::text())->describeWritten(['a']));
    }

    #[Test]
    public function itRefusesAnEmptyClosedSet(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least one word');

        RuleOptionShape::oneOf();
    }

    #[Test]
    public function itRefusesABlankWordInAClosedSet(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('blank word');

        RuleOptionShape::oneOf('info', '  ');
    }
}
