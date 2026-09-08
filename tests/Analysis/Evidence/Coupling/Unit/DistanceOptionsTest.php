<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;

#[CoversClass(DistanceOptions::class)]
final class DistanceOptionsTest extends TestCase
{
    #[Test]
    public function itCoercesAStringIncludeNamespacesEntryToAnArray(): void
    {
        $options = DistanceOptions::fromArray(['include_namespaces' => 'App\\Service']);

        self::assertSame(['App\\Service'], $options->includeNamespaces);
    }

    #[Test]
    public function itPreservesAnArrayIncludeNamespacesEntry(): void
    {
        $options = DistanceOptions::fromArray([
            'include_namespaces' => ['App\\Service', 'App\\Domain'],
        ]);

        self::assertSame(['App\\Service', 'App\\Domain'], $options->includeNamespaces);
    }

    #[Test]
    public function itLeavesIncludeNamespacesNullWhenAbsent(): void
    {
        $options = DistanceOptions::fromArray([]);

        self::assertNull($options->includeNamespaces);
    }
}
