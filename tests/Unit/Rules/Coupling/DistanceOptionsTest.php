<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Rules\Coupling\DistanceOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DistanceOptions::class)]
final class DistanceOptionsTest extends TestCase
{
    #[Test]
    public function fromArrayStringIncludeNamespacesCoercedToArray(): void
    {
        $options = DistanceOptions::fromArray(['include_namespaces' => 'App\\Service']);

        self::assertSame(['App\\Service'], $options->includeNamespaces);
    }

    #[Test]
    public function fromArrayStringExcludeNamespacesCoercedToArray(): void
    {
        $options = DistanceOptions::fromArray(['exclude_namespaces' => 'App\\Legacy']);

        self::assertSame(['App\\Legacy'], $options->excludeNamespaces);
    }

    #[Test]
    public function fromArrayArrayIncludeNamespacesPreserved(): void
    {
        $options = DistanceOptions::fromArray([
            'include_namespaces' => ['App\\Service', 'App\\Domain'],
        ]);

        self::assertSame(['App\\Service', 'App\\Domain'], $options->includeNamespaces);
    }

    #[Test]
    public function fromArrayNullIncludeNamespacesRemainsNull(): void
    {
        $options = DistanceOptions::fromArray([]);

        self::assertNull($options->includeNamespaces);
    }
}
