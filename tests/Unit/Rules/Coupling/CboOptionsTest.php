<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Rules\Coupling\CboOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CboOptions::class)]
final class CboOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEnabledFalseDisablesAllLevels(): void
    {
        $options = CboOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }

    #[Test]
    public function fromArrayWithoutEnabledFalseUsesSubDefaults(): void
    {
        $options = CboOptions::fromArray([]);

        // Empty sub-configs: class defaults enabled, namespace defaults disabled
        self::assertTrue($options->class->isEnabled());
    }
}
