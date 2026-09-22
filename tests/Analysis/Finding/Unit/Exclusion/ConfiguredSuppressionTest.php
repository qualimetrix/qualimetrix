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
    public function itReadsTheRawChannelMapNeededByInputValidation(): void
    {
        self::assertSame(
            ['coupling.cbo:namespace' => [['subtree' => 'App']]],
            ConfiguredSuppression::rawNamespaceChannels([
                'suppressNamespaceChannels' => ['coupling.cbo:namespace' => [['subtree' => 'App']]],
            ]),
        );
    }

    /** Malformed configuration is refused where it is parsed, never here. */
    #[Test]
    public function itReadsNothingRatherThanThrowingOnAMalformedValue(): void
    {
        self::assertSame([], ConfiguredSuppression::rawNamespaceChannels(['suppress_namespace_channels' => 'x']));
    }
}
