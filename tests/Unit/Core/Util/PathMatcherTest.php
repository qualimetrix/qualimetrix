<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\PathMatcher;

#[CoversClass(PathMatcher::class)]
final class PathMatcherTest extends TestCase
{
    public function testIsEmptyReturnsTrueForEmptyPatterns(): void
    {
        $matcher = new PathMatcher([]);

        self::assertTrue($matcher->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenPatternsExist(): void
    {
        $matcher = new PathMatcher(['src/Entity']);

        self::assertFalse($matcher->isEmpty());
    }

    public function testMatchesReturnsFalseForEmptyPatterns(): void
    {
        $matcher = new PathMatcher([]);

        self::assertFalse($matcher->matches('src/Entity/User.php'));
    }

    public function testMatchesReturnsFalseForEmptyFilePath(): void
    {
        $matcher = new PathMatcher(['src/Entity']);

        self::assertFalse($matcher->matches(''));
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('matchingPatternsProvider')]
    public function testMatchesReturnsTrue(string $description, array $patterns, string $filePath): void
    {
        $matcher = new PathMatcher($patterns);

        self::assertTrue($matcher->matches($filePath), $description);
    }

    /**
     * @param list<string> $patterns
     */
    #[DataProvider('nonMatchingPatternsProvider')]
    public function testMatchesReturnsFalse(string $description, array $patterns, string $filePath): void
    {
        $matcher = new PathMatcher($patterns);

        self::assertFalse($matcher->matches($filePath), $description);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function matchingPatternsProvider(): iterable
    {
        // Prefix mode (no glob characters)
        yield 'prefix: exact file match' => [
            'Exact path should match',
            ['src/Entity/User.php'],
            'src/Entity/User.php',
        ];

        yield 'prefix: directory matches file inside' => [
            'Directory prefix should match files inside',
            ['src/Entity'],
            'src/Entity/User.php',
        ];

        yield 'prefix: directory matches nested file' => [
            'Directory prefix should match deeply nested files',
            ['src/Entity'],
            'src/Entity/Sub/Deep.php',
        ];

        yield 'prefix: directory matches itself' => [
            'Prefix should match the directory path itself',
            ['src/Entity'],
            'src/Entity',
        ];

        yield 'prefix: multiple patterns second matches' => [
            'Should match when second pattern matches',
            ['src/DTO', 'src/Entity'],
            'src/Entity/User.php',
        ];

        yield 'prefix: trailing slash normalization on pattern' => [
            'Trailing slash on pattern should be stripped',
            ['src/Entity/'],
            'src/Entity',
        ];

        yield 'prefix: trailing slash normalization on file path' => [
            'Trailing slash on file path should be stripped',
            ['src/Entity'],
            'src/Entity/',
        ];

        // Glob mode (contains *, ?, or [)
        yield 'glob: star matches file in directory' => [
            'Glob * should match files in directory',
            ['src/Entity/*'],
            'src/Entity/User.php',
        ];

        yield 'glob: star matches nested file' => [
            'Glob * should match across directory separators (no FNM_PATHNAME)',
            ['src/Entity/*'],
            'src/Entity/Sub/Deep.php',
        ];

        yield 'glob: filename pattern' => [
            'Glob should match filename patterns',
            ['src/Metrics/*Visitor.php'],
            'src/Metrics/CboVisitor.php',
        ];

        yield 'glob: recursive pattern' => [
            'Glob ** should match recursively',
            ['src/Rules/**/*Options.php'],
            'src/Rules/Complexity/CcnOptions.php',
        ];

        yield 'glob: wildcard in middle' => [
            'Wildcard in middle segment should match',
            ['*/Entity/*'],
            'src/Entity/User.php',
        ];

        yield 'glob: question mark wildcard' => [
            'Question mark should match single character',
            ['src/Entity/User?.php'],
            'src/Entity/UserX.php',
        ];

        // Mixed prefix and glob in same instance
        yield 'mixed: prefix pattern matches' => [
            'Prefix pattern should work alongside glob patterns',
            ['src/Entity', 'src/Metrics/*Visitor.php'],
            'src/Entity/User.php',
        ];

        yield 'mixed: glob pattern matches' => [
            'Glob pattern should work alongside prefix patterns',
            ['src/Entity', 'src/Metrics/*Visitor.php'],
            'src/Metrics/CboVisitor.php',
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function nonMatchingPatternsProvider(): iterable
    {
        // Prefix mode
        yield 'prefix: different directory' => [
            'Non-matching prefix should return false',
            ['src/DTO'],
            'src/Entity/User.php',
        ];

        yield 'prefix: boundary check' => [
            'Prefix should not match partial directory name',
            ['src/Entity'],
            'src/EntityManager/Foo.php',
        ];

        yield 'prefix: sibling file' => [
            'Exact file prefix should not match sibling',
            ['src/Entity/User.php'],
            'src/Entity/UserService.php',
        ];

        yield 'prefix: empty pattern is skipped' => [
            'Empty pattern should not match anything',
            [''],
            'src/Entity/User.php',
        ];

        // Glob mode
        yield 'glob: no match' => [
            'Non-matching glob should return false',
            ['src/DTO/*'],
            'src/Entity/User.php',
        ];

        yield 'glob: filename pattern mismatch' => [
            'Filename glob should not match different suffix',
            ['src/Metrics/*Visitor.php'],
            'src/Metrics/CboCollector.php',
        ];
    }
}
