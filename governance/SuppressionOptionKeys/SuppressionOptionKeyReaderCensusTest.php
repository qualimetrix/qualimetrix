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

    /**
     * A file holding two or more suppression spellings side by side in a list.
     *
     * The shape above only sees a key written *at* the subscript, and a reader
     * that puts its spellings in a constant and subscripts with the loop
     * variable is invisible to it. `RuleInputValidator` was exactly that for
     * `suppress_namespace_channels`, and it was found by a reviewer rather than
     * by this guard. What the pair of shapes recognises together is the real
     * subject: holding the enumeration, however it is later used.
     *
     * Two, not one: a single mention is how the config schema declares an
     * option and how a refusal names it, which are different acts this guard
     * leaves alone.
     */
    private const string HELD_ENUMERATION =
        '/[\'"]suppress[A-Za-z_]*[\'"]\\s*,\\s*[\'"]suppress[A-Za-z_]*[\'"]/';

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

            if (preg_match(self::RAW_READ, $source) === 1 || preg_match(self::HELD_ENUMERATION, $source) === 1) {
                $readers[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($readers);

        self::assertSame([self::READER], $readers, \sprintf(
            'A suppression option key is read, or its spellings are held as a list, outside %s;'
            . ' read the value through ConfiguredSuppression and take the names from FrameworkOptionKeys,'
            . ' which declares each on its own line and so holds no list of its own.',
            self::READER,
        ));
    }
}
