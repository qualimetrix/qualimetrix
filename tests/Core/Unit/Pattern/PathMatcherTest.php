<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\Pattern;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathMatcher;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\PatternMatch;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Pattern\SelectorMatchFailure;

#[CoversClass(PathPattern::class)]
#[CoversClass(PathMatcher::class)]
#[CoversClass(PatternMatch::class)]
#[CoversClass(SelectorMatchFailure::class)]
final class PathMatcherTest extends TestCase
{
    #[Test]
    public function itRendersAnExactPathAsALiteralFullSubjectPredicate(): void
    {
        $pattern = self::path(SelectorKind::Exact, 'src/[Generated]~(old).php');

        self::assertSame(
            '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)\\A(?:src/\\[Generated\\]\\~\\(old\\)\\.php)\\z~',
            $pattern->rendered(),
        );
        self::assertTrue($pattern->matches(RelativePath::fromString('src/[Generated]~(old).php')));
        self::assertFalse($pattern->matches(RelativePath::fromString('src/xGeneratedx~old.php')));
    }

    #[Test]
    public function itMatchesASubtreeRootAndDescendantsButNotAPrefixSibling(): void
    {
        $pattern = self::path(SelectorKind::Subtree, 'src/Entity');

        self::assertTrue($pattern->matches(RelativePath::fromString('src/Entity')));
        self::assertTrue($pattern->matches(RelativePath::fromString('src/Entity/Sub/User.php')));
        self::assertFalse($pattern->matches(RelativePath::fromString('src/EntityManager/User.php')));
    }

    #[Test]
    public function itUsesThePathSeparatorForASubtree(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must use "/" separators');

        self::path(SelectorKind::Subtree, 'src\\Entity');
    }

    #[Test]
    public function itRefusesLeadingTrailingAndEmptyPathSegmentsInsteadOfNormalizingThem(): void
    {
        foreach (['/src/Entity', 'src/Entity/', 'src//Entity'] as $value) {
            try {
                self::path(SelectorKind::Exact, $value);
                self::fail(\sprintf('Expected "%s" to be refused', $value));
            } catch (InvalidArgumentException) {
            }
        }

        self::addToAssertionCount(1);
    }

    #[Test]
    public function itMatchesAnExplicitRegexWithScopedOptions(): void
    {
        $pattern = self::path(SelectorKind::Regex, '(?i:src/entity)/[^/]+\\.php');

        self::assertTrue($pattern->matches(RelativePath::fromString('src/ENTITY/User.php')));
        self::assertFalse($pattern->matches(RelativePath::fromString('src/ENTITY/Nested/User.php')));
    }

    #[Test]
    public function itRejectsAPcreSuccessWhoseSpanWasShortCircuitedByAccept(): void
    {
        $pattern = self::path(SelectorKind::Regex, '(*ACCEPT)');

        self::assertFalse($pattern->matches(RelativePath::fromString('src/Entity/User.php')));
    }

    #[Test]
    public function itRejectsAPcreSuccessWhoseSpanWasResetByK(): void
    {
        $pattern = self::path(SelectorKind::Regex, 'src/\\KEntity/User\\.php');

        self::assertFalse($pattern->matches(RelativePath::fromString('src/Entity/User.php')));
    }

    #[Test]
    public function itRefusesAnInvalidPcreFragmentAtBindingTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not valid PCRE');

        self::path(SelectorKind::Regex, '(');
    }

    #[Test]
    public function itRaisesATypedFailureWhenPcreExhaustsTheMatchBudget(): void
    {
        $definition = new SelectorDefinition(SelectorKind::Regex, '(a+)+');
        $pattern = new PathPattern($definition);

        try {
            $pattern->matches(RelativePath::fromString(str_repeat('a', 4096) . '!'));
            self::fail('Expected the nested quantifier to exhaust the selector match budget');
        } catch (SelectorMatchFailure $failure) {
            self::assertSame($definition, $failure->definition);
            self::assertSame('Backtrack limit exhausted', $failure->pcreDiagnostic);
            self::assertStringContainsString('regex:(a+)+', $failure->getMessage());
        }
    }

    #[Test]
    public function itPreservesTheFirstAuthoredDefinitionForOverlappingAndDuplicatePatterns(): void
    {
        $first = new SelectorDefinition(SelectorKind::Subtree, 'src');
        $duplicate = new SelectorDefinition(SelectorKind::Subtree, 'src');
        $matcher = new PathMatcher([new PathPattern($first), new PathPattern($duplicate)]);

        $match = $matcher->matches(RelativePath::fromString('src/Entity/User.php'));

        self::assertInstanceOf(PatternMatch::class, $match);
        self::assertSame($first, $match->definition);
    }

    #[Test]
    public function itRefusesASelectorListOverTheFixedBudget(): void
    {
        $pattern = self::path(SelectorKind::Exact, 'src/Entity/User.php');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain more than 256 definitions');

        new PathMatcher(array_fill(0, SelectorDefinition::MAX_SELECTOR_COUNT + 1, $pattern));
    }

    #[Test]
    public function itIsEmptyOnlyWhenNoBoundPatternsWereGiven(): void
    {
        self::assertTrue((new PathMatcher([]))->isEmpty());
        self::assertFalse((new PathMatcher([self::path(SelectorKind::Exact, 'src/Entity/User.php')]))->isEmpty());
    }

    private static function path(SelectorKind $kind, string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition($kind, $value));
    }
}
