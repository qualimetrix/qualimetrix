<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;

/**
 * The global namespace is the empty string. It has no parent chain and nothing
 * names it as a parent, so it is an isolated leaf — and consumers that ask the
 * tree which namespaces hold code must see it.
 */
#[CoversClass(NamespaceTree::class)]
final class NamespaceTreeGlobalNamespaceTest extends TestCase
{
    #[Test]
    public function itTreatsTheGlobalNamespaceAsALeaf(): void
    {
        $tree = new NamespaceTree(['', 'App\\Service']);

        self::assertTrue($tree->has(''));
        self::assertTrue($tree->isLeaf(''));
        self::assertContains('', $tree->getLeaves());
        self::assertSame([''], $tree->getDescendantLeaves(''));
    }

    #[Test]
    public function itGivesTheGlobalNamespaceNoParentAndNoChildren(): void
    {
        $tree = new NamespaceTree(['', 'App', 'App\\Service']);

        self::assertNull($tree->getParent(''));
        self::assertSame([], $tree->getChildren(''));
        self::assertSame([], $tree->getAncestors(''));
        self::assertNotContains('', $tree->getParentNamespaces());
    }

    #[Test]
    public function itDoesNotMakeTopLevelNamespacesChildrenOfTheGlobalOne(): void
    {
        $tree = new NamespaceTree(['', 'App\\Service']);

        self::assertNull($tree->getParent('App'));
        self::assertSame(['App'], $tree->getAncestors('App\\Service'));
        self::assertSame(['App\\Service'], $tree->getDescendantLeaves('App'));
    }

    #[Test]
    public function itIsTheOnlyNodeWhenAProjectIsEntirelyGlobal(): void
    {
        $tree = new NamespaceTree(['']);

        self::assertSame([''], $tree->getLeaves());
        self::assertSame([''], $tree->getAllNamespaces());
        self::assertSame([], $tree->getParentNamespaces());
    }
}
