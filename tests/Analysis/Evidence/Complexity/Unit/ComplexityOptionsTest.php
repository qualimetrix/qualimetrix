<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(ComplexityOptions::class)]
final class ComplexityOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesEveryLevelWhenEnabledIsFalse(): void
    {
        $options = ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, ['enabled' => false]));

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->callable->isEnabled());
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function itKeepsDefaultsWhenEnabledIsOmitted(): void
    {
        $options = ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, []));

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itStaysDisabledWhenTheFlatThresholdShorthandIsPresent(): void
    {
        // enabled: false takes priority over the flat `threshold` shorthand
        $options = ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, [
            'enabled' => false,
            'threshold' => 5,
        ]));

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itStaysDisabledWhenHierarchicalLevelKeysArePresent(): void
    {
        $options = ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, [
            'enabled' => false,
            'callable' => ['warning' => 5],
            'class' => ['max_warning' => 10],
        ]));

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itSpreadsTheBareThresholdOnlyIntoTheCallableBandAndKeepsTheClassBlock(): void
    {
        $options = ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, [
            'threshold' => 5,
            'class' => ['max_warning' => 2, 'max_error' => 3],
        ]));

        self::assertSame(5, $options->callable->warning);
        self::assertSame(5, $options->callable->error);
        self::assertTrue($options->class->isEnabled());
        self::assertSame(2, $options->class->maxWarning);
        self::assertSame(3, $options->class->maxError);
    }

    #[Test]
    public function itReadsTheClassBlockWhenNoBareThresholdIsWritten(): void
    {
        $options = ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, ['class' => ['max_warning' => 2, 'max_error' => 3]]));

        self::assertTrue($options->class->isEnabled());
        self::assertSame(2, $options->class->maxWarning);
    }

    /**
     * Regression: `threshold: ~` beside a `class:` block used to open the flat
     * branch on the strength of the key existing, which both silenced the
     * class level and threw the block away — exit 0, no word said.
     */
    #[Test]
    public function itReadsTheClassBlockWhenTheBareThresholdBesideItIsWrittenNull(): void
    {
        $options = ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, [
            'threshold' => null,
            'class' => ['max_warning' => 2, 'max_error' => 3],
        ]));

        self::assertTrue($options->class->isEnabled());
        self::assertSame(2, $options->class->maxWarning);
        self::assertEquals(
            ComplexityOptions::fromResolved(ResolvedOptionsFixture::values(ComplexityOptions::class, ['class' => ['max_warning' => 2, 'max_error' => 3]])),
            $options,
            'the `~` beside the block is worth exactly what leaving it out is worth',
        );
    }
}
