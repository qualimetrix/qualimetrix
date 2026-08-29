<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ProducerName;

/**
 * The grammar is written out here in words, and each shape it refuses is
 * refused on its own.
 *
 * One case per malformed shape rather than a list in one case: a single
 * assertion over several names passes as soon as the FIRST of them throws, so a
 * pattern that stopped refusing three of the four would still be green. Every
 * shape below was accepted before Ш5e3 and is named in the compiler pass that
 * used to accept it.
 */
#[CoversClass(ProducerName::class)]
final class ProducerNameTest extends TestCase
{
    #[Test]
    #[DataProvider('wellFormedNames')]
    public function itAcceptsAWellFormedName(string $name): void
    {
        ProducerName::assertWellFormed($name);

        self::assertSame(1, preg_match(ProducerName::TEMPLATE, $name));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wellFormedNames(): iterable
    {
        yield 'one segment' => ['computed'];
        yield 'two segments' => ['complexity.cyclomatic'];
        yield 'kebab inside a segment' => ['code-smell.long-parameter-list'];
        yield 'three segments' => ['design.type-coverage.param'];
        yield 'digits after the first letter' => ['security.md5'];
    }

    #[Test]
    #[DataProvider('malformedNames')]
    public function itRefusesAMalformedName(string $name, string $why): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage(\sprintf('Producer "%s" is not a well-formed name', $name));

        ProducerName::assertWellFormed($name);
        self::fail($why);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedNames(): iterable
    {
        yield 'empty' => ['', 'a producer with no name at all has no heading to print under'];
        yield 'leading separator' => ['.complexity', 'the family would be empty'];
        yield 'trailing separator' => ['complexity.', 'the trailing segment is empty'];
        yield 'doubled separator' => ['complexity..cyclomatic', 'the middle segment is empty'];
        yield 'upper case' => ['Complexity.Foo', '--group=complexity would not find it, the filter being case-sensitive'];
        yield 'underscore' => ['computed.branch_load', 'snake was the encoding vocabulary Ш5e3 removed'];
        yield 'space inside a segment' => ['complexity.cyclomatic complexity', 'a space is not part of any name'];
        yield 'leading digit' => ['complexity.2ndpass', 'a segment starts with a letter'];
        yield 'doubled hyphen' => ['code--smell.eval', 'kebab separates with one hyphen'];
        yield 'trailing hyphen' => ['code-smell.eval-', 'the trailing kebab word is empty'];
    }

    /**
     * The template is read from the class rather than restated: a grammar
     * written twice is two grammars, agreeing only until one of them moves.
     */
    #[Test]
    public function itPublishesTheTemplateItEnforces(): void
    {
        self::assertSame(1, preg_match(ProducerName::TEMPLATE, 'design.type-coverage.param'));
        self::assertSame(0, preg_match(ProducerName::TEMPLATE, 'computed.branch_load'));
    }
}
