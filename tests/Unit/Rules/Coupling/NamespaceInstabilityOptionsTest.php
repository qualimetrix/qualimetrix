<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Rules\Coupling\NamespaceInstabilityOptions;

#[CoversClass(NamespaceInstabilityOptions::class)]
final class NamespaceInstabilityOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEmptyReturnsEnabled(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function fromArrayWithEnabledTrueIsEnabled(): void
    {
        $options = NamespaceInstabilityOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
    }
}
