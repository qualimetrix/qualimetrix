<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Rules\Coupling\NamespaceCboOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespaceCboOptions::class)]
final class NamespaceCboOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayStringExcludeNamespacesCoercedToArray(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'exclude_namespaces' => 'App\\Legacy',
        ]);

        self::assertSame(['App\\Legacy'], $options->excludeNamespaces);
    }

    #[Test]
    public function fromArrayEmptyReturnsDisabled(): void
    {
        $options = NamespaceCboOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }
}
