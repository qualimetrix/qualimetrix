<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Rules\Coupling\InstabilityOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstabilityOptions::class)]
final class InstabilityOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEnabledFalseDisablesAllLevels(): void
    {
        $options = InstabilityOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
        self::assertFalse($options->class->isEnabled());
        self::assertFalse($options->namespace->isEnabled());
    }
}
