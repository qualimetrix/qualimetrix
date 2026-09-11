<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;

#[CoversClass(RuleOptionShape::class)]
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
        yield 'list names its element' => [
            RuleOptionShape::listOf(RuleOptionShape::nonEmptyText()),
            'a list of a non-empty string',
        ];
        yield 'map names its value' => [
            RuleOptionShape::mapOf(RuleOptionShape::listOf(RuleOptionShape::text())),
            'a map of a list of a string',
        ];
        yield 'block' => [RuleOptionShape::block(), 'a block of options'];
        yield 'union names both branches' => [
            RuleOptionShape::either(RuleOptionShape::text(), RuleOptionShape::listOf(RuleOptionShape::text())),
            'a string or a list of a string',
        ];
    }

    #[Test]
    #[DataProvider('provideWrittenForms')]
    public function itNamesTheFormThatWasWritten(mixed $value, string $expected): void
    {
        self::assertSame($expected, RuleOptionShape::describeWritten($value));
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
}
