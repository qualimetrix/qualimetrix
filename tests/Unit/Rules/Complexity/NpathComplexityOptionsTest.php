<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Complexity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Rules\Complexity\NpathComplexityOptions;

#[CoversClass(NpathComplexityOptions::class)]
final class NpathComplexityOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEnabledFalseDisablesAllLevels(): void
    {
        $options = NpathComplexityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->method->isEnabled());
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function fromArrayWithoutEnabledFalseKeepsDefaults(): void
    {
        $options = NpathComplexityOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }
}
