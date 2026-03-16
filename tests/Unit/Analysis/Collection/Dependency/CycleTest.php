<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection\Dependency;

use AiMessDetector\Analysis\Collection\Dependency\Cycle;
use AiMessDetector\Core\Symbol\SymbolPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cycle::class)]
final class CycleTest extends TestCase
{
    public function testGetSize(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B', 'App\\C']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']),
        );

        $this->assertSame(3, $cycle->getSize());
        $this->assertCount(3, $cycle->getClasses());
        $this->assertCount(4, $cycle->getPath());
    }

    public function testToString(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B', 'App\\C']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']),
        );

        $this->assertSame(
            'App\\A → App\\B → App\\C → App\\A',
            $cycle->toString(),
        );
    }

    public function testToShortString(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\Service\\UserService', 'App\\Service\\OrderService']),
            path: $this->paths(['App\\Service\\UserService', 'App\\Service\\OrderService', 'App\\Service\\UserService']),
        );

        $this->assertSame(
            'UserService → OrderService → UserService',
            $cycle->toShortString(),
        );
    }

    public function testToShortStringWithoutNamespace(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['A', 'B']),
            path: $this->paths(['A', 'B', 'A']),
        );

        $this->assertSame(
            'A → B → A',
            $cycle->toShortString(),
        );
    }

    public function testDirectCycle(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\A']),
        );

        $this->assertSame(2, $cycle->getSize());
        $this->assertSame('App\\A → App\\B → App\\A', $cycle->toString());
        $this->assertSame('A → B → A', $cycle->toShortString());
    }

    public function testToTruncatedShortStringSmallCycle(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['A', 'B', 'C']),
            path: $this->paths(['A', 'B', 'C', 'A']),
        );

        // Small cycles should not be truncated
        $this->assertSame('A → B → C → A', $cycle->toTruncatedShortString(5));
    }

    public function testToTruncatedShortStringLargeCycle(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 10; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0'; // Close the cycle

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );

        $truncated = $cycle->toTruncatedShortString(5);
        $this->assertSame('C0 → C1 → C2 → C3 → C4 → ... (5 more)', $truncated);
    }

    public function testToTruncatedShortStringExactlyAtLimit(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 5; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0'; // Close the cycle (6 entries in path, maxEntries=5 + 1 closing = 6)

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );

        // Path has 6 entries, maxEntries=5 → 5+1=6 → fits, no truncation
        $this->assertSame('C0 → C1 → C2 → C3 → C4 → C0', $cycle->toTruncatedShortString(5));
    }

    public function testGetSizeCategorySmall(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['A', 'B', 'C']),
            path: $this->paths(['A', 'B', 'C', 'A']),
        );
        $this->assertSame('small', $cycle->getSizeCategory());
    }

    public function testGetSizeCategorySmallBoundary(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 5; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        $this->assertSame('small', $cycle->getSizeCategory());
    }

    public function testGetSizeCategoryMedium(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 10; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        $this->assertSame('medium', $cycle->getSizeCategory());
    }

    public function testGetSizeCategoryMediumBoundary(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 20; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        $this->assertSame('medium', $cycle->getSizeCategory());
    }

    public function testGetSizeCategoryLarge(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 21; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        $this->assertSame('large', $cycle->getSizeCategory());
    }

    public function testToStructuredData(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B', 'App\\C']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']),
        );

        $data = $cycle->toStructuredData();

        $this->assertSame(['A', 'B', 'C', 'A'], $data['cycle']);
        $this->assertSame(3, $data['length']);
        $this->assertSame('small', $data['category']);
    }

    public function testToStructuredDataLargeCycleContainsFullPath(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 25; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );

        $data = $cycle->toStructuredData();

        $this->assertSame(25, $data['length']);
        $this->assertSame('large', $data['category']);
        // Full path is preserved in structured data
        $this->assertCount(26, $data['cycle']);
        $this->assertSame('C0', $data['cycle'][0]);
        $this->assertSame('C0', $data['cycle'][25]);
    }

    /**
     * @param list<string> $fqns
     *
     * @return list<SymbolPath>
     */
    private function paths(array $fqns): array
    {
        return array_map(
            static fn(string $fqn): SymbolPath => SymbolPath::fromClassFqn($fqn),
            $fqns,
        );
    }
}
