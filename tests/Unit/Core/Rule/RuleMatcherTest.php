<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\RuleMatcher;

#[CoversClass(RuleMatcher::class)]
final class RuleMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matchesProvider(): iterable
    {
        // Exact matches
        yield 'exact match simple' => ['complexity', 'complexity', true];
        yield 'exact match dotted' => ['complexity.cyclomatic', 'complexity.cyclomatic', true];
        yield 'exact match triple' => ['complexity.cyclomatic.method', 'complexity.cyclomatic.method', true];

        // Prefix matches
        yield 'prefix one level' => ['complexity', 'complexity.cyclomatic', true];
        yield 'prefix two levels' => ['complexity', 'complexity.cyclomatic.method', true];
        yield 'prefix dotted' => ['complexity.cyclomatic', 'complexity.cyclomatic.method', true];
        yield 'prefix size group' => ['size', 'size.method-count', true];
        yield 'prefix code-smell group' => ['code-smell', 'code-smell.eval', true];

        // Non-matches
        yield 'reverse does not match' => ['complexity.cyclomatic', 'complexity', false];
        yield 'reverse triple' => ['complexity.cyclomatic.method', 'complexity.cyclomatic', false];
        yield 'different rule' => ['complexity', 'size', false];
        yield 'partial name no dot' => ['complex', 'complexity', false];
        yield 'partial name with dot' => ['complex', 'complexity.cyclomatic', false];
        yield 'similar prefix' => ['size', 'sized.something', false];
        yield 'empty pattern' => ['', 'complexity', false];
        yield 'empty subject' => ['complexity', '', false];
        yield 'both empty' => ['', '', true];
    }

    #[DataProvider('matchesProvider')]
    #[Test]
    public function itMatches(string $pattern, string $subject, bool $expected): void
    {
        self::assertSame($expected, RuleMatcher::matches($pattern, $subject));
    }

    #[Test]
    public function itAnyMatchesReturnsTrueWhenOneMatches(): void
    {
        self::assertTrue(RuleMatcher::anyMatches(['size', 'coupling'], 'size.method-count'));
    }

    #[Test]
    public function itAnyMatchesReturnsFalseWhenNoneMatch(): void
    {
        self::assertFalse(RuleMatcher::anyMatches(['size', 'coupling'], 'complexity.cyclomatic'));
    }

    #[Test]
    public function itAnyMatchesWithEmptyPatterns(): void
    {
        self::assertFalse(RuleMatcher::anyMatches([], 'complexity'));
    }

    #[Test]
    public function itAnyMatchesExactAndPrefix(): void
    {
        self::assertTrue(RuleMatcher::anyMatches(['complexity.cyclomatic'], 'complexity.cyclomatic'));
        self::assertTrue(RuleMatcher::anyMatches(['complexity.cyclomatic'], 'complexity.cyclomatic.method'));
        self::assertFalse(RuleMatcher::anyMatches(['complexity.cyclomatic'], 'complexity'));
    }

    #[Test]
    public function itAnyReverseMatchesSubjectIsPrefixOfPattern(): void
    {
        // 'complexity' is prefix of 'complexity.method' → true
        self::assertTrue(RuleMatcher::anyReverseMatches(['complexity.method'], 'complexity'));
        // 'complexity' is prefix of 'complexity.cyclomatic.method' → true
        self::assertTrue(RuleMatcher::anyReverseMatches(['complexity.cyclomatic.method'], 'complexity'));
    }

    #[Test]
    public function itAnyReverseMatchesNoMatch(): void
    {
        self::assertFalse(RuleMatcher::anyReverseMatches(['size.method-count'], 'complexity'));
    }

    #[Test]
    public function itAnyReverseMatchesExact(): void
    {
        self::assertTrue(RuleMatcher::anyReverseMatches(['complexity'], 'complexity'));
    }

    #[Test]
    public function itAnyReverseMatchesEmpty(): void
    {
        self::assertFalse(RuleMatcher::anyReverseMatches([], 'complexity'));
    }
}
