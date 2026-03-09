<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Complexity;

use AiMessDetector\Rules\Complexity\ComplexityOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComplexityOptions::class)]
final class ComplexityOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEnabledFalseDisablesAllLevels(): void
    {
        $options = ComplexityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->method->isEnabled());
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function fromArrayWithoutEnabledFalseKeepsDefaults(): void
    {
        $options = ComplexityOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function fromArrayEnabledFalseWithLegacyKeysStillDisables(): void
    {
        // enabled: false takes priority over legacy keys
        $options = ComplexityOptions::fromArray([
            'enabled' => false,
            'warningThreshold' => 5,
            'errorThreshold' => 10,
        ]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function fromArrayEnabledFalseWithHierarchicalKeysStillDisables(): void
    {
        $options = ComplexityOptions::fromArray([
            'enabled' => false,
            'method' => ['warning' => 5],
            'class' => ['max_warning' => 10],
        ]);

        self::assertFalse($options->isEnabled());
    }
}
