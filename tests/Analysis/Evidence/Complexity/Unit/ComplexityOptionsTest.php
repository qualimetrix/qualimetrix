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
}
