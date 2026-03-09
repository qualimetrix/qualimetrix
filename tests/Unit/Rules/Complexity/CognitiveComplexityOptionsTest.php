<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Complexity;

use AiMessDetector\Rules\Complexity\CognitiveComplexityOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CognitiveComplexityOptions::class)]
final class CognitiveComplexityOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEnabledFalseDisablesAllLevels(): void
    {
        $options = CognitiveComplexityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->method->isEnabled());
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function fromArrayWithoutEnabledFalseKeepsDefaults(): void
    {
        $options = CognitiveComplexityOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }
}
