<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterionKind;
use Qualimetrix\Analysis\Policy\Architecture\Layer\PatternScope;

#[CoversClass(PatternScope::class)]
final class PatternScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function provideContainmentCases(): iterable
    {
        yield 'catch-all contains a subtree' => ['**', 'App\\Service\\**', true];
        yield 'catch-all does not contain itself' => ['**', '**', false];
        yield 'subtree does not contain the catch-all' => ['App\\**', '**', false];
        yield 'parent subtree contains a child subtree' => ['App\\**', 'App\\Http\\**', true];
        yield 'child subtree does not contain its parent' => ['App\\Http\\**', 'App\\**', false];
        yield 'identical patterns are not strict' => ['App\\Http\\**', 'App\\Http\\**', false];
        yield 'inclusive prefix contains its own strict subtree' => ['App\\Http', 'App\\Http\\**', true];
        yield 'strict subtree does not contain its inclusive prefix' => ['App\\Http\\**', 'App\\Http', false];
        yield 'segment boundary is respected' => ['App\\Http', 'App\\HttpKernel\\**', false];
        yield 'single star behaves like a strict subtree' => ['App\\*', 'App\\Http\\**', true];
        yield 'trailing backslash is cosmetic' => ['App\\', 'App\\Http\\**', true];
        yield 'siblings are unrelated' => ['App\\Http\\**', 'App\\Cli\\**', false];
    }

    #[Test]
    #[DataProvider('provideContainmentCases')]
    public function itDecidesStrictContainmentOfNamespaceSubtrees(string $outer, string $inner, bool $expected): void
    {
        $outerScope = PatternScope::fromCriterion(self::pattern($outer));
        $innerScope = PatternScope::fromCriterion(self::pattern($inner));

        self::assertNotNull($outerScope);
        self::assertNotNull($innerScope);
        self::assertSame($expected, $outerScope->strictlyContains($innerScope));
    }

    /**
     * @return iterable<string, array{0: MatchedCriterion}>
     */
    public static function provideUndecidableCriteria(): iterable
    {
        yield 'mid-pattern wildcard' => [self::pattern('App\\**\\Foo')];
        yield 'partial segment wildcard' => [self::pattern('**\\*Service')];
        yield 'character class' => [self::pattern('App\\[AB]pi\\**')];
        yield 'single-character wildcard' => [self::pattern('App\\Ser?ice\\**')];
        yield 'unexpanded capture' => [self::pattern('App\\{module}\\**')];
        yield 'suffix criterion' => [new MatchedCriterion(MatchedCriterionKind::Suffix, 'Repository')];
        yield 'implements criterion' => [new MatchedCriterion(MatchedCriterionKind::Implements, 'App\\Marker')];
        yield 'extends criterion' => [new MatchedCriterion(MatchedCriterionKind::Extends, 'App\\Base')];
        yield 'attribute criterion' => [new MatchedCriterion(MatchedCriterionKind::Attribute, 'App\\Tag')];
        yield 'backslash-only pattern' => [self::pattern('\\')];
    }

    /**
     * A criterion whose covered set is not a namespace subtree must be
     * undecidable, so the caller keeps its diagnostic instead of guessing.
     */
    #[Test]
    #[DataProvider('provideUndecidableCriteria')]
    public function itRefusesToScopeCriteriaThatAreNotNamespaceSubtrees(MatchedCriterion $criterion): void
    {
        self::assertNull(PatternScope::fromCriterion($criterion));
    }

    private static function pattern(string $value): MatchedCriterion
    {
        return new MatchedCriterion(MatchedCriterionKind::Pattern, $value);
    }
}
