<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PatternMatch;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(NamespaceMatcher::class)]
#[CoversClass(PatternMatch::class)]
final class NamespaceMatcherTest extends TestCase
{
    /**
     * Every production call site of the primitive, with the number of calls it
     * makes. A surface missing here — or one that normalizes its own pattern
     * before handing it over — is how the trailing-backslash behaviour came to
     * differ between surfaces.
     *
     * @var array<string, int>
     */
    private const array SELECTOR_SURFACES = [
        'src/Analysis/Evidence/ComputedMetrics/Health/Contract/DrillDown/HealthScoreDrillDown.php' => 2,
        'src/Analysis/Evidence/ComputedMetrics/Health/Offender/WorstOffenderBuilder.php' => 1,
        'src/Analysis/Evidence/Coupling/DistanceRule.php' => 1,
        'src/Analysis/Policy/Architecture/Layer/Expansion/TupleExtractor.php' => 1,
        'src/Analysis/Policy/Architecture/Layer/LayerCriteriaMatcher.php' => 1,
        'src/Reporting/Filter/FindingFilter.php' => 2,
    ];

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
    public function itMatchesReturnsNullForEmptyPrefixes(): void
    {
        $matcher = new NamespaceMatcher([]);

        self::assertNull($matcher->matches('App\\Entity\\User'));
    }

    #[Test]
    public function itMatchesReturnsNullForEmptyNamespace(): void
    {
        $matcher = new NamespaceMatcher(['App\\Entity']);

        self::assertNull($matcher->matches(''));
    }

    #[Test]
    public function itMatchesReturnsNullForZeroMatches(): void
    {
        $matcher = new NamespaceMatcher(['App\\DTO']);

        self::assertNull($matcher->matches('App\\Entity\\User'));
    }

    #[Test]
    public function itMatchesReturnsTheMatchedPatternForOneConfiguredPrefix(): void
    {
        $matcher = new NamespaceMatcher(['App\\Entity']);

        $result = $matcher->matches('App\\Entity\\User');

        self::assertInstanceOf(PatternMatch::class, $result);
        self::assertSame('App\\Entity', $result->pattern);
    }

    #[Test]
    public function itMatchesReturnsTheFirstMatchedPatternWhenSeveralPrefixesMatch(): void
    {
        $matcher = new NamespaceMatcher(['App\\*Repository', 'App\\Entity']);

        $result = $matcher->matches('App\\UserRepository');

        self::assertInstanceOf(PatternMatch::class, $result);
        self::assertSame(
            'App\\*Repository',
            $result->pattern,
            'The first pattern in configuration order must win when several patterns match.',
        );
    }

    #[Test]
    public function itMatchesReturnsTheNormalizedPatternWhenItHasATrailingBackslash(): void
    {
        $matcher = new NamespaceMatcher(['App\\Entity\\']);

        $result = $matcher->matches('App\\Entity\\User');

        self::assertInstanceOf(PatternMatch::class, $result);
        self::assertSame('App\\Entity', $result->pattern);
    }

    /**
     * @param list<string> $prefixes
     */
    #[DataProvider('matchingPrefixesProvider')]
    #[Test]
    public function itMatchesReturnsTrue(string $description, array $prefixes, string $namespace): void
    {
        $matcher = new NamespaceMatcher($prefixes);

        self::assertNotNull($matcher->matches($namespace), $description);
    }

    /**
     * @param list<string> $prefixes
     */
    #[DataProvider('nonMatchingPrefixesProvider')]
    #[Test]
    public function itMatchesReturnsFalse(string $description, array $prefixes, string $namespace): void
    {
        $matcher = new NamespaceMatcher($prefixes);

        self::assertNull($matcher->matches($namespace), $description);
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
    public function itNormalizesTrailingBackslashInPrefixMode(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\Entity\\', 'App\\Entity'));
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\Entity\\', 'App\\Entity\\User'));
        self::assertFalse(NamespaceMatcher::matchesSingle('App\\Entity\\', 'App\\EntityManager'));
    }

    #[Test]
    public function itNormalizesTrailingBackslashInGlobMode(): void
    {
        self::assertTrue(NamespaceMatcher::matchesSingle('App\\*Repository\\', 'App\\UserRepository'));
    }

    #[Test]
    public function itTreatsAnAllBackslashPatternAsEmpty(): void
    {
        self::assertFalse(NamespaceMatcher::matchesSingle('\\\\', 'App\\Entity'));
    }

    #[Test]
    public function itNormalizesTrailingBackslashForInstancePatternsToo(): void
    {
        self::assertNotNull((new NamespaceMatcher(['App\\Entity\\']))->matches('App\\Entity\\User'));
    }

    #[Test]
    public function itLeavesPatternNormalizationToThePrimitiveOnEverySurface(): void
    {
        $root = \dirname(__DIR__, 4);
        $found = [];
        $compensating = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/src',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            \assert($file instanceof SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = self::codeWithoutComments((string) file_get_contents($file->getPathname()));
            $calls = preg_match_all('/NamespaceMatcher::matchesSingle\(\s*(?<first>[^,]+),/', $source, $matches);

            if ($calls === 0 || $calls === false) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $found[$relative] = $calls;

            foreach ($matches['first'] as $argument) {
                if (preg_match('/\b(rtrim|trim|str_replace|preg_replace)\s*\(/', $argument) === 1) {
                    $compensating[] = $relative . ': ' . trim($argument);
                }
            }
        }

        ksort($found);
        $expected = self::SELECTOR_SURFACES;
        ksort($expected);

        self::assertSame($expected, $found, 'Every production call site of the primitive must be registered here.');
        self::assertSame([], $compensating, 'Normalization belongs to matchesSingle(), not to its callers.');
    }

    /**
     * Docblocks name the primitive as often as code calls it, so the sweep
     * must see code only.
     */
    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                $code .= \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? ' ' : $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
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
