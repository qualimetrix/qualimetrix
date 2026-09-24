<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Exclusion;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
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

    /**
     * The factory takes a suppression option out of a producer's options
     * through the reader rather than re-deriving its spellings itself; the
     * value comes back as written, malformed or not, for the decoder to judge.
     */
    #[Test]
    public function itTakesAValueOutUnderEitherSpellingAndLeavesTheRest(): void
    {
        $options = ['suppress_paths' => 'not-a-list', 'suppressPaths' => null, 'warning' => 3];

        self::assertSame('not-a-list', ConfiguredSuppression::take($options, FrameworkOptionKeys::PATHS));
        self::assertSame(['warning' => 3], $options);

        $options = ['suppressNamespaceChannels' => 'x'];

        self::assertSame('x', ConfiguredSuppression::take($options, FrameworkOptionKeys::NAMESPACE_CHANNELS));
        self::assertSame([], $options);
    }

    #[Test]
    public function itRefusesToTakeAKeyThatIsNotASuppressionOption(): void
    {
        $options = ['warning' => 3];

        $this->expectException(LogicException::class);

        ConfiguredSuppression::take($options, 'warning');
    }
}
