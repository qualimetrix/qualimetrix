<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Rules\Coupling\InstabilityOptions;

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
