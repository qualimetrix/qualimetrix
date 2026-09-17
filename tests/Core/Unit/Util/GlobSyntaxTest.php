<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\GlobSyntax;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * The alphabet, and the property that makes one copy of it worth having.
 */
#[CoversClass(GlobSyntax::class)]
final class GlobSyntaxTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function itRecognisesAGlobByItsCharacters(string $pattern, bool $isGlob): void
    {
        self::assertSame($isGlob, GlobSyntax::isGlob($pattern));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function providePatterns(): iterable
    {
        yield 'star' => ['src/*.php', true];
        yield 'question mark' => ['src/A?.php', true];
        yield 'bracket' => ['src/[ab].php', true];
        yield 'plain literal' => ['src/Legacy', false];
        yield 'empty' => ['', false];

        // `fnmatch()` with FNM_NOESCAPE expands no braces, so a brace is an
        // ordinary character to every matcher that applies a pattern.
        yield 'brace' => ['{legacy}', false];
    }

    /**
     * The matchers read the same alphabet, so a brace stays literal on both
     * sides of the run: `fnmatch()` is never reached for it, and prefix
     * matching is what a `{legacy}` value actually gets.
     */
    #[Test]
    public function itAgreesWithTheMatcherThatAppliesTheValue(): void
    {
        self::assertFalse(NamespaceMatcher::isGlob('{Legacy}'));
        self::assertTrue(NamespaceMatcher::matchesSingle('{Legacy}', '{Legacy}\Deep'));
        self::assertFalse(NamespaceMatcher::matchesSingle('{Legacy}', 'legacy'));
    }
}
