<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\GlobSyntax;
use Qualimetrix\Core\Util\NamespaceMatcher;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * The alphabet, and the property that makes one copy of it worth having.
 *
 * The last case is a static guard: a second hand-written list of glob
 * characters anywhere in `src/` is how the judge and the appliers came to
 * disagree about `{`, and a list is exactly what nobody notices going stale.
 */
#[CoversClass(GlobSyntax::class)]
final class GlobSyntaxTest extends TestCase
{
    /** The only file allowed to enumerate the glob characters. */
    private const string SOURCE_OF_TRUTH = 'src/Core/Util/GlobSyntax.php';

    /** A `str_contains()` test for `?` — the middle character of any restated alphabet. */
    private const string RESTATED_ALPHABET = '/str_contains\([^)]*,\s*\'\?\'\s*\)/';

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

    #[Test]
    public function itIsTheOnlyPlaceInSourceThatEnumeratesTheGlobCharacters(): void
    {
        $root = \dirname(__DIR__, 4);
        $enumerators = [];

        /** @var SplFileInfo $file */
        foreach (new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')),
            '/\.php$/',
        ) as $file) {
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, $file->getPathname());

            if (preg_match(self::RESTATED_ALPHABET, $source) === 1) {
                $enumerators[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        self::assertSame([], $enumerators, \sprintf(
            'The glob alphabet is restated outside %s; read it from GlobSyntax instead.',
            self::SOURCE_OF_TRUTH,
        ));
    }
}
