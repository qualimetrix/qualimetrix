<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Namespace_;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Namespace_\NamespaceTree;

#[CoversClass(NamespaceTree::class)]
final class NamespaceTreeTest extends TestCase
{
    #[Test]
    public function two_siblings_discover_parent(): void
    {
        $tree = new NamespaceTree(['App\\Service', 'App\\Domain']);

        self::assertTrue($tree->has('App'));
        self::assertTrue($tree->has('App\\Service'));
        self::assertTrue($tree->has('App\\Domain'));

        self::assertFalse($tree->isLeaf('App'));
        self::assertTrue($tree->isLeaf('App\\Service'));
        self::assertTrue($tree->isLeaf('App\\Domain'));

        self::assertNull($tree->getParent('App'));
        self::assertSame('App', $tree->getParent('App\\Service'));
        self::assertSame('App', $tree->getParent('App\\Domain'));

        $children = $tree->getChildren('App');
        sort($children);
        self::assertSame(['App\\Domain', 'App\\Service'], $children);
    }

    #[Test]
    public function input_namespace_with_children_becomes_parent(): void
    {
        // App is in input but has a child → it's a parent, not a leaf
        $tree = new NamespaceTree(['App', 'App\\Service']);

        self::assertFalse($tree->isLeaf('App'));
        self::assertTrue($tree->isLeaf('App\\Service'));

        self::assertSame(['App\\Service'], $tree->getChildren('App'));
        self::assertSame([], $tree->getChildren('App\\Service'));
    }

    #[Test]
    public function deep_nesting_discovers_all_intermediates(): void
    {
        $tree = new NamespaceTree(['A\\B\\C\\D']);

        self::assertTrue($tree->has('A'));
        self::assertTrue($tree->has('A\\B'));
        self::assertTrue($tree->has('A\\B\\C'));
        self::assertTrue($tree->has('A\\B\\C\\D'));

        self::assertFalse($tree->isLeaf('A'));
        self::assertFalse($tree->isLeaf('A\\B'));
        self::assertFalse($tree->isLeaf('A\\B\\C'));
        self::assertTrue($tree->isLeaf('A\\B\\C\\D'));

        self::assertSame(['A\\B\\C', 'A\\B', 'A'], $tree->getAncestors('A\\B\\C\\D'));
        self::assertSame(['A\\B', 'A'], $tree->getAncestors('A\\B\\C'));
        self::assertSame([], $tree->getAncestors('A'));
    }

    #[Test]
    public function single_segment_is_leaf_and_root(): void
    {
        $tree = new NamespaceTree(['App']);

        self::assertTrue($tree->isLeaf('App'));
        self::assertTrue($tree->has('App'));
        self::assertNull($tree->getParent('App'));
        self::assertSame([], $tree->getAncestors('App'));
        self::assertSame([], $tree->getChildren('App'));
    }

    #[Test]
    public function empty_input(): void
    {
        $tree = new NamespaceTree([]);

        self::assertSame([], $tree->getLeaves());
        self::assertSame([], $tree->getParentNamespaces());
        self::assertSame([], $tree->getAllNamespaces());
        self::assertFalse($tree->has('App'));
        self::assertFalse($tree->isLeaf('App'));
    }

    #[Test]
    public function empty_string_is_ignored(): void
    {
        $tree = new NamespaceTree(['', 'App']);

        self::assertFalse($tree->has(''));
        self::assertTrue($tree->has('App'));
        self::assertSame(['App'], $tree->getLeaves());
    }

    #[Test]
    public function get_descendant_leaves(): void
    {
        $tree = new NamespaceTree(['App\\Service\\User', 'App\\Service\\Admin', 'App\\Domain\\Entity']);

        $serviceLeaves = $tree->getDescendantLeaves('App\\Service');
        sort($serviceLeaves);
        self::assertSame(['App\\Service\\Admin', 'App\\Service\\User'], $serviceLeaves);

        $appLeaves = $tree->getDescendantLeaves('App');
        sort($appLeaves);
        self::assertSame(['App\\Domain\\Entity', 'App\\Service\\Admin', 'App\\Service\\User'], $appLeaves);

        // Leaf returns itself
        self::assertSame(['App\\Service\\User'], $tree->getDescendantLeaves('App\\Service\\User'));
    }

    #[Test]
    public function get_leaves_and_parents(): void
    {
        $tree = new NamespaceTree(['App\\Service', 'App\\Domain', 'Vendor\\Lib']);

        $leaves = $tree->getLeaves();
        sort($leaves);
        self::assertSame(['App\\Domain', 'App\\Service', 'Vendor\\Lib'], $leaves);

        $parents = $tree->getParentNamespaces();
        sort($parents);
        self::assertSame(['App', 'Vendor'], $parents);
    }

    #[Test]
    public function get_descendants_returns_all_nodes_in_subtree(): void
    {
        $tree = new NamespaceTree(['App\\Service\\User', 'App\\Service\\Admin', 'App\\Domain']);

        $descendants = $tree->getDescendants('App');
        sort($descendants);
        self::assertSame(['App\\Domain', 'App\\Service', 'App\\Service\\Admin', 'App\\Service\\User'], $descendants);

        $serviceDescendants = $tree->getDescendants('App\\Service');
        sort($serviceDescendants);
        self::assertSame(['App\\Service\\Admin', 'App\\Service\\User'], $serviceDescendants);

        // Leaf has no descendants
        self::assertSame([], $tree->getDescendants('App\\Domain'));

        // Unknown has no descendants
        self::assertSame([], $tree->getDescendants('Unknown'));
    }

    #[Test]
    public function get_all_namespaces(): void
    {
        $tree = new NamespaceTree(['App\\Service', 'App\\Domain']);

        $all = $tree->getAllNamespaces();
        sort($all);
        self::assertSame(['App', 'App\\Domain', 'App\\Service'], $all);
    }

    #[Test]
    public function unknown_namespace_returns_safe_defaults(): void
    {
        $tree = new NamespaceTree(['App\\Service']);

        self::assertFalse($tree->has('Unknown'));
        self::assertFalse($tree->isLeaf('Unknown'));
        self::assertNull($tree->getParent('Unknown'));
        self::assertSame([], $tree->getChildren('Unknown'));
        self::assertSame([], $tree->getAncestors('Unknown'));
        self::assertSame([], $tree->getDescendantLeaves('Unknown'));
    }

    #[Test]
    public function duplicate_input_handled_correctly(): void
    {
        $tree = new NamespaceTree(['App\\Service', 'App\\Service', 'App\\Domain']);

        $children = $tree->getChildren('App');
        sort($children);
        self::assertSame(['App\\Domain', 'App\\Service'], $children);
    }
}
