<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Exclusion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;

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
    /** The two files that must read every option through this class. */
    private const array CONSUMERS = [
        'src/Analysis/Finding/FindingExclusionLedger.php',
        'src/Analysis/Finding/SuppressionBinding/UnboundSuppressionAudit.php',
    ];

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
     * Neither consumer names a suppression option key itself.
     *
     * A quoted `suppress*` literal in either file is one side enumerating the
     * options again — exactly the shape that let the ledger apply three and the
     * audit judge two. Docblock prose is not a literal and does not trip this.
     */
    #[Test]
    public function itIsTheOnlyPlaceEitherConsumerNamesASuppressionOptionKey(): void
    {
        $root = \dirname(__DIR__, 5);

        foreach (self::CONSUMERS as $relative) {
            $source = file_get_contents($root . '/' . $relative);
            self::assertIsString($source, $relative);

            preg_match_all('/[\'"](suppress[A-Za-z_]*)[\'"]/', $source, $matches);

            self::assertSame([], $matches[1], \sprintf(
                '%s names a suppression option key itself; read it through ConfiguredSuppression instead.',
                $relative,
            ));
        }
    }
}
