<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\NamespaceCboOptions;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(NamespaceCboOptions::class)]
final class NamespaceCboOptionsTest extends TestCase
{
    #[Test]
    public function itDefaultsToEnabledWhenConstructedFromAnEmptyArray(): void
    {
        $options = NamespaceCboOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceCboOptions::class, []));

        self::assertTrue($options->isEnabled());
    }
}
