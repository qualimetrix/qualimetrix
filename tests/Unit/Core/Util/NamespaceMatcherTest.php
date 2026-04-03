<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\NamespaceMatcher;

#[CoversClass(NamespaceMatcher::class)]
final class NamespaceMatcherTest extends TestCase
{
    public function testIsEmptyReturnsTrueForEmptyPrefixes(): void
    {
        $matcher = new NamespaceMatcher([]);

        self::assertTrue($matcher->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenPrefixesExist(): void
    {
        $matcher = new NamespaceMatcher(['App\\Entity']);

        self::assertFalse($matcher->isEmpty());
    }

    public function testMatchesReturnsFalseForEmptyPrefixes(): void
    {
        $matcher = new NamespaceMatcher([]);

        self::assertFalse($matcher->matches('App\\Entity\\User'));
    }

    public function testMatchesReturnsFalseForEmptyNamespace(): void
    {
        $matcher = new NamespaceMatcher(['App\\Entity']);

        self::assertFalse($matcher->matches(''));
    }

    /**
     * @param list<string> $prefixes
     */
    #[DataProvider('matchingPrefixesProvider')]
    public function testMatchesReturnsTrue(string $description, array $prefixes, string $namespace): void
    {
        $matcher = new NamespaceMatcher($prefixes);

        self::assertTrue($matcher->matches($namespace), $description);
    }

    /**
     * @param list<string> $prefixes
     */
    #[DataProvider('nonMatchingPrefixesProvider')]
    public function testMatchesReturnsFalse(string $description, array $prefixes, string $namespace): void
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
}
