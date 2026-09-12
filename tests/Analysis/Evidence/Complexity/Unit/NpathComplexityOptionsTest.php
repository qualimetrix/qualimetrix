<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\NpathComplexityOptions;

#[CoversClass(NpathComplexityOptions::class)]
final class NpathComplexityOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesEveryLevelWhenEnabledIsFalse(): void
    {
        $options = NpathComplexityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->callable->isEnabled());
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function itKeepsDefaultsWhenEnabledIsOmitted(): void
    {
        $options = NpathComplexityOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    /**
     * Regression: `threshold: ~` beside a `class:` block used to open the flat
     * branch on the strength of the key existing, which both silenced the
     * class level and threw the block away — exit 0, no word said.
     */
    #[Test]
    public function itReadsTheClassBlockWhenTheBareThresholdBesideItIsWrittenNull(): void
    {
        // The class level is off by default here, so the block turns it on:
        // the discarded-block defect is invisible without something to lose.
        $block = ['class' => ['enabled' => true, 'max_warning' => 2, 'max_error' => 3]];

        $options = NpathComplexityOptions::fromArray(['threshold' => null] + $block);

        self::assertTrue($options->class->isEnabled());
        self::assertSame(2, $options->class->maxWarning);
        self::assertEquals(
            NpathComplexityOptions::fromArray($block),
            $options,
            'the `~` beside the block is worth exactly what leaving it out is worth',
        );
    }

    #[Test]
    public function itStillLetsABareThresholdWithAValueDiscardTheClassBlock(): void
    {
        $options = NpathComplexityOptions::fromArray([
            'threshold' => 50,
            'class' => ['enabled' => true, 'max_warning' => 2, 'max_error' => 3],
        ]);

        self::assertFalse($options->class->isEnabled());
        self::assertSame(50, $options->callable->warning);
    }
}
