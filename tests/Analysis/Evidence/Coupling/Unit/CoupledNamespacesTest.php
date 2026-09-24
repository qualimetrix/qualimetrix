<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\CoupledNamespaces;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

#[CoversClass(CoupledNamespaces::class)]
final class CoupledNamespacesTest extends TestCase
{
    #[Test]
    public function itCouplesEveryEnclosingRegionTheEdgeLeaves(): void
    {
        $coupled = CoupledNamespaces::of(AdjacencyGraphBuilder::build([
            'App\\Domain\\Order\\Order' => ['Vendor\\Money\\Amount'],
        ]));

        foreach (['App\\Domain\\Order', 'App\\Domain', 'App'] as $region) {
            self::assertSame(1, $coupled->countFor($region), $region);
        }
        foreach (['Vendor\\Money', 'Vendor'] as $region) {
            self::assertSame(1, $coupled->countFor($region), $region);
        }
    }

    #[Test]
    public function itKeepsAnEdgeBetweenTwoSubNamespacesInsideTheirCommonParent(): void
    {
        $coupled = CoupledNamespaces::of(AdjacencyGraphBuilder::build([
            'App\\A\\X' => ['App\\B\\Y'],
        ]));

        self::assertSame(0, $coupled->countFor('App'));
        self::assertSame(1, $coupled->countFor('App\\A'));
        self::assertSame(1, $coupled->countFor('App\\B'));
    }

    /**
     * The far side is the namespace declaring the class, never cut to the
     * region's depth: two targets under one vendor root are two namespaces.
     */
    #[Test]
    public function itNamesTheFarSideByItsDeclaringNamespace(): void
    {
        $coupled = CoupledNamespaces::of(AdjacencyGraphBuilder::build([
            'App\\A\\X' => ['Vendor\\One\\P', 'Vendor\\Two\\Q', 'Vendor\\Two\\R'],
        ]));

        self::assertSame(2, $coupled->countFor('App'));
    }

    /**
     * The own scope is exactly one namespace: a sub-namespace is on the far
     * side like any other, and an ancestor's own count holds only its own
     * edges.
     */
    #[Test]
    public function itCountsTheOwnScopeOverExactlyOneNamespace(): void
    {
        $coupled = CoupledNamespaces::of(AdjacencyGraphBuilder::build([
            'App\\Svc\\S' => ['App\\Svc\\Exception\\Oops', 'Ext\\Z'],
            'App\\Svc\\Exception\\Oops' => ['Ext2\\Q'],
        ]));

        self::assertSame(2, $coupled->ownCountFor('App\\Svc'));
        self::assertSame(2, $coupled->ownCountFor('App\\Svc\\Exception'));
        self::assertSame(0, $coupled->ownCountFor('App'));
        self::assertSame(2, $coupled->countFor('App\\Svc'));
    }

    /**
     * The global namespace is a region of its own, not the parent of every
     * namespace: a prefix of '' would otherwise swallow the whole project.
     */
    #[Test]
    public function itTreatsTheGlobalNamespaceAsItsOwnRegion(): void
    {
        $coupled = CoupledNamespaces::of(AdjacencyGraphBuilder::build([
            'GlobalHelper' => ['App\\Service'],
        ]));

        self::assertSame(1, $coupled->countFor(''));
        self::assertSame(1, $coupled->countFor('App'));
    }
}
