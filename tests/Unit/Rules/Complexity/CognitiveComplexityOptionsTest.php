<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Complexity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Rules\Complexity\CognitiveComplexityOptions;

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
