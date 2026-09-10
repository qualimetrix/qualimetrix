<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Exclusion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * The reader, and the property that makes it worth having.
 *
 * The reader itself is small; what it is for is that the applying side and the
 * judging side stop enumerating the options separately. So the last case here
 * is a static guard rather than a behaviour test: it reddens when either side
 * reaches for an option key of its own again, which is how the two came to
 * disagree about `suppress_namespace_channels` in the first place.
 */
#[CoversClass(ConfiguredSuppression::class)]
final class ConfiguredSuppressionTest extends TestCase
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
    public function itReadsEitherSpellingOfEachOption(): void
    {
        self::assertSame(['a'], ConfiguredSuppression::paths(['suppress_paths' => ['a']]));
        self::assertSame(['a'], ConfiguredSuppression::paths(['suppressPaths' => 'a']));
        self::assertSame(['n'], ConfiguredSuppression::namespaces(['suppress_namespaces' => ['n']]));
        self::assertSame(['n'], ConfiguredSuppression::namespaces(['suppressNamespaces' => 'n']));
        self::assertSame(
            [['selector' => 'coupling.cbo:namespace', 'pattern' => 'n']],
            ConfiguredSuppression::namespaceChannelPatterns([
                'suppressNamespaceChannels' => ['coupling.cbo:namespace' => ['n']],
            ]),
        );
    }

    /** Malformed configuration is refused where it is parsed, never here. */
    #[Test]
    public function itReadsNothingRatherThanThrowingOnAMalformedValue(): void
    {
        self::assertSame([], ConfiguredSuppression::paths(['suppress_paths' => 42]));
        self::assertSame([], ConfiguredSuppression::namespaces([]));
        self::assertSame([], ConfiguredSuppression::rawNamespaceChannels(['suppress_namespace_channels' => 'x']));
        self::assertSame([], ConfiguredSuppression::namespaceChannelPatterns([
            'suppress_namespace_channels' => ['ok' => [1, 2]],
        ]));
    }

    /**
     * No file in `src/` but this one reads a suppression option key.
     *
     * The earlier form of this guard listed the two consumers it knew about,
     * which is the enumeration-kept-separately this class exists to remove: a
     * third copy of all six spellings sat in Reporting, agreeing by
     * coincidence, while the docblock here claimed there was one reader. The
     * guard searches the whole tree instead, so a fourth reader anywhere is
     * caught without anyone updating a list.
     */
    #[Test]
    public function itIsTheOnlyPlaceInSourceThatReadsASuppressionOptionKey(): void
    {
        $root = \dirname(__DIR__, 5);
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
