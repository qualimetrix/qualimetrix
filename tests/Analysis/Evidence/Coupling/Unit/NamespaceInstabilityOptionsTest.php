<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\NamespaceInstabilityOptions;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(NamespaceInstabilityOptions::class)]
final class NamespaceInstabilityOptionsTest extends TestCase
{
    #[Test]
    public function itDefaultsToEnabledWhenTheOptionsArrayIsEmpty(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, []));

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itIsDisabledWhenTheEnabledFlagIsFalse(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, ['enabled' => false]));

        self::assertFalse($options->isEnabled());
    }
}
