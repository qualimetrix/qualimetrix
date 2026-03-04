<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection\Dependency;

use AiMessDetector\Analysis\Collection\Dependency\Cycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cycle::class)]
final class CycleTest extends TestCase
{
    public function testGetSize(): void
    {
        $cycle = new Cycle(
            classes: ['App\\A', 'App\\B', 'App\\C'],
            path: ['App\\A', 'App\\B', 'App\\C', 'App\\A'],
        );

        $this->assertSame(3, $cycle->getSize());
        $this->assertSame(['App\\A', 'App\\B', 'App\\C'], $cycle->getClasses());
        $this->assertSame(['App\\A', 'App\\B', 'App\\C', 'App\\A'], $cycle->getPath());
    }

    public function testToString(): void
    {
        $cycle = new Cycle(
            classes: ['App\\A', 'App\\B', 'App\\C'],
            path: ['App\\A', 'App\\B', 'App\\C', 'App\\A'],
        );

        $this->assertSame(
            'App\\A → App\\B → App\\C → App\\A',
            $cycle->toString(),
        );
    }

    public function testToShortString(): void
    {
        $cycle = new Cycle(
            classes: ['App\\Service\\UserService', 'App\\Service\\OrderService'],
            path: ['App\\Service\\UserService', 'App\\Service\\OrderService', 'App\\Service\\UserService'],
        );

        $this->assertSame(
            'UserService → OrderService → UserService',
            $cycle->toShortString(),
        );
    }

    public function testToShortStringWithoutNamespace(): void
    {
        $cycle = new Cycle(
            classes: ['A', 'B'],
            path: ['A', 'B', 'A'],
        );

        $this->assertSame(
            'A → B → A',
            $cycle->toShortString(),
        );
    }

    public function testDirectCycle(): void
    {
        $cycle = new Cycle(
            classes: ['App\\A', 'App\\B'],
            path: ['App\\A', 'App\\B', 'App\\A'],
        );

        $this->assertSame(2, $cycle->getSize());
        $this->assertSame('App\\A → App\\B → App\\A', $cycle->toString());
        $this->assertSame('A → B → A', $cycle->toShortString());
    }
}
