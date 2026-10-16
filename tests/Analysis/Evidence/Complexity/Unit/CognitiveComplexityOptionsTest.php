<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityOptions;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(CognitiveComplexityOptions::class)]
final class CognitiveComplexityOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesAllLevelsWhenTheTopLevelEnabledFlagIsFalse(): void
    {
        $options = CognitiveComplexityOptions::fromResolved(ResolvedOptionsFixture::values(CognitiveComplexityOptions::class, ['enabled' => false]));

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->callable->isEnabled());
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function itKeepsTheDefaultEnabledStateWhenNoEnabledKeyIsGiven(): void
    {
        $options = CognitiveComplexityOptions::fromResolved(ResolvedOptionsFixture::values(CognitiveComplexityOptions::class, []));

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
        $block = ['class' => ['max_warning' => 2, 'max_error' => 3]];

        $options = CognitiveComplexityOptions::fromResolved(ResolvedOptionsFixture::values(CognitiveComplexityOptions::class, ['threshold' => null] + $block));

        self::assertTrue($options->class->isEnabled());
        self::assertSame(2, $options->class->maxWarning);
        self::assertEquals(
            CognitiveComplexityOptions::fromResolved(ResolvedOptionsFixture::values(CognitiveComplexityOptions::class, $block)),
            $options,
            'the `~` beside the block is worth exactly what leaving it out is worth',
        );
    }

    #[Test]
    public function itSpreadsTheBareThresholdOnlyIntoTheCallableBandAndKeepsTheClassBlock(): void
    {
        $options = CognitiveComplexityOptions::fromResolved(ResolvedOptionsFixture::values(CognitiveComplexityOptions::class, [
            'threshold' => 5,
            'class' => ['max_warning' => 2, 'max_error' => 3],
        ]));

        self::assertTrue($options->class->isEnabled());
        self::assertSame(2, $options->class->maxWarning);
        self::assertSame(3, $options->class->maxError);
        self::assertSame(5, $options->callable->warning);
    }
}
