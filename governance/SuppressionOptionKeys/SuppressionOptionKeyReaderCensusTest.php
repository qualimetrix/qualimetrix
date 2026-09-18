<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\SuppressionOptionKeys;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * No file in `src/` but `ConfiguredSuppression` reads a suppression option key.
 *
 * The earlier form of this guard listed the two consumers it knew about,
 * which is the enumeration-kept-separately this class exists to remove: a
 * third copy of all six spellings sat in Reporting, agreeing by
 * coincidence, while the docblock here claimed there was one reader. The
 * guard searches the whole tree instead, so a fourth reader anywhere is
 * caught without anyone updating a list.
 */
final class SuppressionOptionKeyReaderCensusTest extends TestCase
{
    /** The only file in `src/` allowed to read a suppression option key. */
    private const string READER = 'src/Analysis/Finding/Exclusion/ConfiguredSuppression.php';

    /**
     * A `suppress*` key — quoted, or reached through the config schema's
     * constant — subscripted off an array: the shape of reading one producer's
     * raw options. Declaring the option (the config schema) or
     * validating it (the CLI validators) names the key without subscripting an
     * array with it, and is a different act this guard leaves alone.
     */
    private const string RAW_READ = '/\\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z0-9_]+)*\\[\\s*(?:[\'"]suppress|ConfigSchema::SUPPRESS_)/';

    #[Test]
    public function itIsTheOnlyPlaceInSourceThatReadsASuppressionOptionKey(): void
    {
        $root = \dirname(__DIR__, 2);
        $readers = [];

        /** @var SplFileInfo $file */
        foreach (new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')),
            '/\\.php$/',
        ) as $file) {
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, $file->getPathname());

            if (preg_match(self::RAW_READ, $source) === 1) {
                $readers[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($readers);

        self::assertSame([self::READER], $readers, \sprintf(
            'A suppression option key is read outside %s; read it through ConfiguredSuppression instead.',
            self::READER,
        ));
    }
}
