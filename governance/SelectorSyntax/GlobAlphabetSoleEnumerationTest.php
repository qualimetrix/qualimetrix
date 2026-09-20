<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SelectorSyntax;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A second hand-written list of glob characters anywhere in `src/` is how the
 * judge and the appliers came to disagree about `{`, and a list is exactly
 * what nobody notices going stale.
 */
final class GlobAlphabetSoleEnumerationTest extends TestCase
{
    /** The only file allowed to enumerate the glob characters. */
    private const string SOURCE_OF_TRUTH = 'src/Core/Pattern/GlobSyntax.php';

    /** A `str_contains()` test for `?` — the middle character of any restated alphabet. */
    private const string RESTATED_ALPHABET = '/str_contains\([^)]*,\s*\'\?\'\s*\)/';

    /**
     * Both ways this guard can pass while proving nothing: a scan with no files
     * to read, and a pattern that no longer matches the thing it hunts. The
     * verdict below is an empty-list assertion, so either would read as green.
     */
    #[Test]
    public function itHasSourceFilesToScanAndAPatternThatStillMatchesARestatedAlphabet(): void
    {
        self::assertNotSame([], self::sourceFiles(), 'The scanned source tree is empty.');

        self::assertSame(
            1,
            preg_match(self::RESTATED_ALPHABET, "str_contains(\$pattern, '?')"),
            'The pattern no longer matches a restated alphabet, so the scan hunts for nothing.',
        );
    }

    #[Test]
    public function itIsTheOnlyPlaceInSourceThatEnumeratesTheGlobCharacters(): void
    {
        $root = \dirname(__DIR__, 2);
        $enumerators = [];

        foreach (self::sourceFiles() as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, $file);

            if (preg_match(self::RESTATED_ALPHABET, $source) === 1) {
                $enumerators[] = str_replace($root . '/', '', $file);
            }
        }

        self::assertSame([], $enumerators, \sprintf(
            'The glob alphabet is restated outside %s; read it from GlobSyntax instead.',
            self::SOURCE_OF_TRUTH,
        ));
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
