<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SelectorSyntax;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * A second hand-written list of glob characters anywhere in `src/` is how the
 * judge and the appliers came to disagree about `{`, and a list is exactly
 * what nobody notices going stale.
 */
final class GlobAlphabetSoleEnumerationTest extends TestCase
{
    /** The only file allowed to enumerate the glob characters. */
    private const string SOURCE_OF_TRUTH = 'src/Core/Util/GlobSyntax.php';

    /** A `str_contains()` test for `?` — the middle character of any restated alphabet. */
    private const string RESTATED_ALPHABET = '/str_contains\([^)]*,\s*\'\?\'\s*\)/';

    #[Test]
    public function itIsTheOnlyPlaceInSourceThatEnumeratesTheGlobCharacters(): void
    {
        $root = \dirname(__DIR__, 2);
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
