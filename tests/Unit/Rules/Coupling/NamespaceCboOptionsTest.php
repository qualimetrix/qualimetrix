<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Rules\Coupling\NamespaceCboOptions;

#[CoversClass(NamespaceCboOptions::class)]
final class NamespaceCboOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayEmptyReturnsEnabled(): void
    {
        $options = NamespaceCboOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }
}
