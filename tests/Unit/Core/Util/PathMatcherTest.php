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
        $matcher = new PathMatcher(['src/Entity/*']);

        self::assertFalse($matcher->isEmpty());
    }

    public function testMatchesReturnsFalseForEmptyPatterns(): void
    {
        $matcher = new PathMatcher([]);

        self::assertFalse($matcher->matches('src/Entity/User.php'));
    }

    public function testMatchesReturnsFalseForEmptyFilePath(): void
    {
        $matcher = new PathMatcher(['src/Entity/*']);

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
        yield 'exact match' => [
            'Exact path should match',
            ['src/Entity/User.php'],
            'src/Entity/User.php',
        ];

        yield 'glob star matches file in directory' => [
            'Glob * should match files in directory',
            ['src/Entity/*'],
            'src/Entity/User.php',
        ];

        yield 'glob star matches nested file' => [
            'Glob * should match across directory separators (no FNM_PATHNAME)',
            ['src/Entity/*'],
            'src/Entity/Sub/Deep.php',
        ];

        yield 'wildcard in middle' => [
            'Wildcard in middle segment should match',
            ['*/Entity/*'],
            'src/Entity/User.php',
        ];

        yield 'multiple patterns second matches' => [
            'Should match when second pattern matches',
            ['src/DTO/*', 'src/Entity/*'],
            'src/Entity/User.php',
        ];

        yield 'trailing slash normalization on pattern' => [
            'Trailing slash on pattern should be stripped before matching',
            ['src/Entity/'],
            'src/Entity',
        ];

        yield 'trailing slash normalization on file path' => [
            'Trailing slash on file path should be stripped before matching',
            ['src/Entity'],
            'src/Entity/',
        ];

        yield 'question mark wildcard' => [
            'Question mark should match single character',
            ['src/Entity/User?.php'],
            'src/Entity/UserX.php',
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function nonMatchingPatternsProvider(): iterable
    {
        yield 'no match' => [
            'Non-matching pattern should return false',
            ['src/DTO/*'],
            'src/Entity/User.php',
        ];

        yield 'partial path mismatch' => [
            'Partial path should not match',
            ['src/Entity/User.php'],
            'src/Entity/UserService.php',
        ];
    }
}
