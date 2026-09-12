<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;

#[CoversClass(ComplexityOptions::class)]
final class ComplexityOptionsTest extends TestCase
{
    #[Test]
    public function itDisablesEveryLevelWhenEnabledIsFalse(): void
    {
        $options = ComplexityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->callable->isEnabled());
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function itKeepsDefaultsWhenEnabledIsOmitted(): void
    {
        $options = ComplexityOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itStaysDisabledWhenTheFlatThresholdShorthandIsPresent(): void
    {
        // enabled: false takes priority over the flat `threshold` shorthand
        $options = ComplexityOptions::fromArray([
            'enabled' => false,
            'threshold' => 5,
        ]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itStaysDisabledWhenHierarchicalLevelKeysArePresent(): void
    {
        $options = ComplexityOptions::fromArray([
            'enabled' => false,
            'callable' => ['warning' => 5],
            'class' => ['max_warning' => 10],
        ]);

        self::assertFalse($options->isEnabled());
    }

    /**
     * The bare `threshold` replaces the level blocks rather than adding to
     * them, and it does so by carrying a VALUE. The class level is not merely
     * left at its default here: the shorthand switches it off, so a `class:`
     * block beside the key both goes unread and reports nothing. The website
     * says exactly this; this is what it says it about.
     */
    #[Test]
    public function itLetsTheBareThresholdDiscardTheClassBlockAndSilenceTheClassLevel(): void
    {
        $options = ComplexityOptions::fromArray([
            'threshold' => 5,
            'class' => ['max_warning' => 2, 'max_error' => 3],
        ]);

        self::assertSame(5, $options->callable->warning);
        self::assertSame(5, $options->callable->error);
        self::assertFalse($options->class->isEnabled());
        self::assertSame(
            ComplexityOptions::fromArray(['threshold' => 5])->class->maxWarning,
            $options->class->maxWarning,
            'the class block is gone, not merged: the level holds what the bare shorthand alone leaves it',
        );
    }

    #[Test]
    public function itReadsTheClassBlockWhenNoBareThresholdIsWritten(): void
    {
        $options = ComplexityOptions::fromArray(['class' => ['max_warning' => 2, 'max_error' => 3]]);

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
        $options = ComplexityOptions::fromArray([
            'threshold' => null,
            'class' => ['max_warning' => 2, 'max_error' => 3],
        ]);

        self::assertTrue($options->class->isEnabled());
        self::assertSame(2, $options->class->maxWarning);
        self::assertEquals(
            ComplexityOptions::fromArray(['class' => ['max_warning' => 2, 'max_error' => 3]]),
            $options,
            'the `~` beside the block is worth exactly what leaving it out is worth',
        );
    }
}
