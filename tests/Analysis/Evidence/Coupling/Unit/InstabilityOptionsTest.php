<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\InstabilityOptions;

#[CoversClass(InstabilityOptions::class)]
final class InstabilityOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesAllLevelsWhenTheTopLevelEnabledFlagIsFalse(): void
    {
        $options = InstabilityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }

    #[Test]
    public function itAppliesTheFlatThresholdShorthandUniformlyToBothLevels(): void
    {
        $options = InstabilityOptions::fromArray(['threshold' => 0.5]);

        self::assertSame(0.5, $options->class->maxWarning);
        self::assertSame(0.5, $options->class->maxError);
        self::assertSame(0.5, $options->namespace->maxWarning);
        self::assertSame(0.5, $options->namespace->maxError);
    }

    #[Test]
    public function itAdvertisesTheThresholdShorthandKey(): void
    {
        self::assertTrue(InstabilityOptions::acceptedOptionKeys()->accepts('threshold'));
    }

    #[Test]
    public function itStillSupportsTheNestedClassAndNamespaceForm(): void
    {
        $options = InstabilityOptions::fromArray([
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ]);

        self::assertSame(0.6, $options->class->maxWarning);
        self::assertSame(0.8, $options->class->maxError);
        self::assertSame(0.7, $options->namespace->maxWarning);
        self::assertSame(0.9, $options->namespace->maxError);
    }

    #[Test]
    public function itThrowsWhenTheFlatThresholdIsMixedWithBareMaxWarningInTheSameConfigArray(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Cannot mix "threshold" with "max_warning"/"max_error"');

        InstabilityOptions::fromArray(['threshold' => 0.5, 'max_warning' => 0.6]);
    }

    #[Test]
    public function itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray(): void
    {
        // Same deliberate precedence choice as CboOptions — see its test of
        // the same name for the rationale.
        $options = InstabilityOptions::fromArray([
            'threshold' => 0.5,
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ]);

        self::assertSame(0.5, $options->class->maxWarning);
        self::assertSame(0.5, $options->class->maxError);
        self::assertSame(0.5, $options->namespace->maxWarning);
        self::assertSame(0.5, $options->namespace->maxError);
    }

    /**
     * Regression: `threshold: ~` beside the level blocks used to open the flat
     * branch on the strength of the key existing, and the blocks were thrown
     * away — exit 0, no word said.
     */
    #[Test]
    public function itReadsTheLevelBlocksWhenTheFlatThresholdBesideThemIsWrittenNull(): void
    {
        $blocks = [
            'class' => ['max_warning' => 0.6, 'max_error' => 0.8],
            'namespace' => ['max_warning' => 0.7, 'max_error' => 0.9],
        ];

        $options = InstabilityOptions::fromArray(['threshold' => null] + $blocks);

        self::assertSame(0.6, $options->class->maxWarning);
        self::assertSame(0.7, $options->namespace->maxWarning);
        self::assertEquals(
            InstabilityOptions::fromArray($blocks),
            $options,
            'the `~` beside the blocks is worth exactly what leaving it out is worth',
        );
    }

    /**
     * Regression: the mixing guard used to fire on key presence, so a
     * `max_warning: ~` that wrote no value at all was reported as a second
     * mode clashing with the first.
     */
    #[Test]
    public function itDoesNotCallItAMixWhenTheGraduatedKeyBesideTheFlatThresholdIsWrittenNull(): void
    {
        $options = InstabilityOptions::fromArray(['threshold' => 0.5, 'max_warning' => null]);

        self::assertSame(0.5, $options->class->maxWarning);
        self::assertSame(0.5, $options->class->maxError);
    }
}
