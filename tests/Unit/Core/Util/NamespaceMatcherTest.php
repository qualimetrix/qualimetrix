<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\NamespaceMatcher;

#[CoversClass(NamespaceMatcher::class)]
final class NamespaceMatcherTest extends TestCase
{
    #[Test]
    public function itIsEmptyReturnsTrueForEmptyPrefixes(): void
    {
        $matcher = new NamespaceMatcher([]);

        self::assertTrue($matcher->isEmpty());
    }

    #[Test]
    public function itIsEmptyReturnsFalseWhenPrefixesExist(): void
    {
        $matcher = new NamespaceMatcher(['App\\Entity']);

        self::assertFalse($matcher->isEmpty());
    }

    #[Test]
    public function itMatchesReturnsFalseForEmptyPrefixes(): void
    {
        $matcher = new NamespaceMatcher([]);

        self::assertFalse($matcher->matches('App\\Entity\\User'));
    }

    #[Test]
    public function itMatchesReturnsFalseForEmptyNamespace(): void
    {
        $matcher = new NamespaceMatcher(['App\\Entity']);

        self::assertFalse($matcher->matches(''));
    }

    /**
     * @param list<string> $prefixes
     */
    #[DataProvider('matchingPrefixesProvider')]
    #[Test]
    public function itMatchesReturnsTrue(string $description, array $prefixes, string $namespace): void
    {
        $matcher = new NamespaceMatcher($prefixes);

        self::assertTrue($matcher->matches($namespace), $description);
    }

    /**
     * @param list<string> $prefixes
     */
    #[DataProvider('nonMatchingPrefixesProvider')]
    #[Test]
    public function itMatchesReturnsFalse(string $description, array $prefixes, string $namespace): void
    {
        $matcher = new NamespaceMatcher($prefixes);

        self::assertFalse($matcher->matches($namespace), $description);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function matchingPrefixesProvider(): iterable
    {
        yield 'exact match' => [
            'Exact namespace should match',
            ['App\\Entity'],
            'App\\Entity',
        ];

        yield 'prefix matches child namespace' => [
            'Prefix should match child namespace',
            ['App\\Entity'],
            'App\\Entity\\User',
        ];

        yield 'prefix matches deeply nested namespace' => [
            'Prefix should match deeply nested namespace',
            ['App\\Entity'],
            'App\\Entity\\Sub\\Deep',
        ];

        yield 'multiple prefixes second matches' => [
            'Should match when second prefix matches',
            ['App\\DTO', 'App\\Entity'],
            'App\\Entity\\User',
        ];

        yield 'trailing backslash normalization' => [
            'Trailing backslash on prefix should be stripped before matching',
            ['App\\Entity\\'],
            'App\\Entity',
        ];

        // Glob mode
        yield 'glob: wildcard matches namespace' => [
            'Glob * should match namespace segment',
            ['App\\*Repository'],
            'App\\UserRepository',
        ];

        yield 'glob: wildcard in middle' => [
            'Glob * in middle should match',
            ['App\\*\\User'],
            'App\\Entity\\User',
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function nonMatchingPrefixesProvider(): iterable
    {
        yield 'different namespace' => [
            'Non-matching prefix should return false',
            ['App\\DTO'],
            'App\\Entity\\User',
        ];

        yield 'partial prefix boundary' => [
            'Prefix should not match partial namespace segment',
            ['App\\Core'],
            'App\\CoreExtra\\Foo',
        ];

        yield 'sibling namespace' => [
            'Prefix should not match sibling namespace',
            ['App\\Entity\\User'],
            'App\\Entity\\UserService',
        ];

        yield 'empty prefix is skipped' => [
            'Empty prefix should not match anything',
            [''],
            'App\\Entity\\User',
        ];

        // Glob mode
        yield 'glob: no match' => [
            'Non-matching glob should return false',
            ['App\\*Repository'],
            'App\\UserService',
        ];
    }

    // ------------------------------------------------------------------
    // Static helper: NamespaceMatcher::matchesSingle
    // ------------------------------------------------------------------

    #[Test]
    public function itMatchesSingleReturnsFalseForEmptyPattern(): void
    {
        self::assertFalse(NamespaceMatcher::matchesSingle('', 'App\\Entity'));
    }

    #[Test]
    public function itMatchesSingleReturnsFalseForEmptyNamespace(): void
    {
        self::assertFalse(NamespaceMatcher::matchesSingle('App\\Entity', ''));
    }

    #[Test]
    public function itMatchesSingleReturnsFalseWhenBothEmpty(): void
    {
        self::assertFalse(NamespaceMatcher::matchesSingle('', ''));
    }

    #[Test]
    public function itMatchesSinglePrefixExact(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\Entity', 'App\\Entity'));
    }

    #[Test]
    public function itMatchesSinglePrefixChild(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\Entity', 'App\\Entity\\User'));
    }

    #[Test]
    public function itMatchesSinglePrefixDeeplyNested(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\Entity', 'App\\Entity\\Sub\\Deep'));
    }

    #[Test]
    public function itMatchesSinglePrefixRespectsNamespaceBoundary(): void
    {
        self::assertFalse(
            NamespaceMatcher::matchesSingle('App\\Entity', 'App\\EntityManager\\Foo'),
            'App\\Entity must not match App\\EntityManager — namespace boundaries are enforced.',
        );
    }

    #[Test]
    public function itMatchesSinglePrefixDoesNotMatchSibling(): void
    {
        self::assertFalse(
            NamespaceMatcher::matchesSingle('App\\Entity\\User', 'App\\Entity\\UserService'),
        );
    }

    #[Test]
    public function itMatchesSingleGlobStarWildcard(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\*Repository', 'App\\UserRepository'));
    }

    #[Test]
    public function itMatchesSingleGlobStarMiddle(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\*\\User', 'App\\Entity\\User'));
    }

    #[Test]
    public function itMatchesSingleGlobQuestionMark(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\?oo', 'App\\Foo'));
    }

    #[Test]
    public function itMatchesSingleGlobCharClass(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\[ABC]oo', 'App\\Aoo'));
    }

    #[Test]
    public function itMatchesSingleGlobNoMatch(): void
    {
        self::assertFalse(NamespaceMatcher::matchesSingle('App\\*Repository', 'App\\UserService'));
    }

    #[Test]
    public function itMatchesSingleDoesNotNormalizeTrailingBackslash(): void
    {
        // matchesSingle is the per-pattern primitive — caller normalizes if needed.
        // A trailing backslash makes the prefix-mode boundary check fail because
        // the namespace doesn't end with '\\\\'.
        self::assertFalse(
            NamespaceMatcher::matchesSingle('App\\Entity\\', 'App\\Entity'),
            'matchesSingle treats trailing backslash as part of the pattern — normalization is the caller\'s job.',
        );
    }

    // ------------------------------------------------------------------
    // Static helper: NamespaceMatcher::isGlob
    // ------------------------------------------------------------------

    #[DataProvider('globPatternProvider')]
    #[Test]
    public function itIsGlobReturnsTrueForGlobCharacters(string $pattern): void
    {
        self::assertTrue(NamespaceMatcher::isGlob($pattern));
    }

    #[DataProvider('nonGlobPatternProvider')]
    #[Test]
    public function itIsGlobReturnsFalseForLiteralPatterns(string $pattern): void
    {
        self::assertFalse(NamespaceMatcher::isGlob($pattern));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function globPatternProvider(): iterable
    {
        yield 'single star' => ['App\\*'];
        yield 'star in middle' => ['App\\*\\Foo'];
        yield 'double star' => ['App\\**\\Foo'];
        yield 'question mark' => ['App\\?oo'];
        yield 'char class' => ['App\\[ABC]oo'];
        yield 'multiple wildcards' => ['App\\*\\?oo'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonGlobPatternProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'plain namespace' => ['App\\Entity'];
        yield 'single segment' => ['App'];
        yield 'trailing backslash' => ['App\\Entity\\'];
    }
}
