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
