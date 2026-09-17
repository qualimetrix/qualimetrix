<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Exclusion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;

/**
 * The reader, and the property that makes it worth having.
 */
#[CoversClass(ConfiguredSuppression::class)]
final class ConfiguredSuppressionTest extends TestCase
{
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
}
