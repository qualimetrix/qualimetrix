<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleFamily;

#[CoversClass(RuleFamily::class)]
final class RuleFamilyTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNames')]
    public function itReadsTheFamilyOffTheProducerName(string $producerRuleName, string $expected): void
    {
        self::assertSame($expected, RuleFamily::of($producerRuleName));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNames(): iterable
    {
        yield 'two segments' => ['complexity.cyclomatic', 'complexity'];
        yield 'hyphenated family' => ['code-smell.boolean-argument', 'code-smell'];
        yield 'three segments' => ['size.method-count.class', 'size'];
        yield 'dotless name is its own family' => ['computed', 'computed'];
    }

    /** A producer listed under an empty heading is the outcome this refusal exists to prevent. */
    #[Test]
    #[DataProvider('provideNamesWithoutAFamily')]
    public function itRefusesANameWithNoFirstSegment(string $producerRuleName): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('has no family');

        RuleFamily::of($producerRuleName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNamesWithoutAFamily(): iterable
    {
        yield 'empty name' => [''];
        yield 'leading separator' => ['.orphan'];
    }
}
