<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Namespace_;

/**
 * Immutable tree structure representing the namespace hierarchy.
 *
 * Built from a list of "leaf" namespaces (those with direct class/function symbols).
 * Discovers intermediate parent namespaces automatically.
 *
 * A namespace is considered a "leaf" if it has no children in the tree.
 * If "App" and "App\Service" are both in the input, "App" becomes a parent
 * (not a leaf) because it has children.
 */
final readonly class NamespaceTree
{
    /** @var array<string, true> */
    private array $leafSet;

    /** @var array<string, list<string>> parent → direct children */
    private array $children;

    /** @var array<string, string> child → parent */
    private array $parent;

    /** @var array<string, true> all nodes (leaves + discovered parents) */
    private array $allNodes;

    /**
     * @param list<string> $leafNamespaces Namespaces that contain direct symbols
     */
    public function __construct(array $leafNamespaces)
    {
        $children = [];
        /** @var array<string, true> child→parent edge deduplication */
        $edgeSeen = [];
        $parent = [];
        $allNodes = [];

        // Register all input namespaces (deduplicate)
        foreach ($leafNamespaces as $ns) {
            if ($ns === '') {
                continue;
            }

            $allNodes[$ns] = true;
        }

        // Discover parents by walking up each namespace
        foreach ($allNodes as $ns => $_) {
            $child = $ns;
            $lastSlash = strrpos($child, '\\');

            while ($lastSlash !== false) {
                $parentNs = substr($child, 0, $lastSlash);

                // Record parent→child relationship (deduplicate via edge set)
                $edgeKey = $parentNs . "\0" . $child;

                if (!isset($edgeSeen[$edgeKey])) {
                    $edgeSeen[$edgeKey] = true;
                    $children[$parentNs][] = $child;
                }

                $parent[$child] = $parentNs;

                // If this parent is already known, its ancestors are already built
                if (isset($allNodes[$parentNs])) {
                    break;
                }

                $allNodes[$parentNs] = true;
                $child = $parentNs;
                $lastSlash = strrpos($child, '\\');
            }
        }

        // A leaf is a node with no children
        $leafSet = [];

        foreach ($allNodes as $ns => $_) {
            if (!isset($children[$ns])) {
                $leafSet[$ns] = true;
            }
        }

        $this->leafSet = $leafSet;
        $this->children = $children;
        $this->parent = $parent;
        $this->allNodes = $allNodes;
    }

    /**
     * Whether the namespace is a leaf (has no children).
     */
    public function isLeaf(string $namespace): bool
    {
        return isset($this->leafSet[$namespace]);
    }

    /**
     * Returns the direct parent namespace, or null if root.
     */
    public function getParent(string $namespace): ?string
    {
        return $this->parent[$namespace] ?? null;
    }

    /**
     * Returns direct children of the namespace.
     *
     * @return list<string>
     */
    public function getChildren(string $namespace): array
    {
        return $this->children[$namespace] ?? [];
    }

    /**
     * Returns all ancestors bottom-up, excluding self.
     *
     * @return list<string>
     */
    public function getAncestors(string $namespace): array
    {
        $ancestors = [];
        $current = $namespace;

        while (isset($this->parent[$current])) {
            $current = $this->parent[$current];
            $ancestors[] = $current;
        }

        return $ancestors;
    }

    /**
     * Returns all leaf descendants recursively.
     *
     * @return list<string>
     */
    public function getDescendantLeaves(string $namespace): array
    {
        if ($this->isLeaf($namespace)) {
            return [$namespace];
        }

        $leaves = [];

        foreach ($this->children[$namespace] ?? [] as $child) {
            foreach ($this->getDescendantLeaves($child) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }

    /**
     * Returns all descendants recursively (both leaves and intermediate parents).
     *
     * @return list<string>
     */
    public function getDescendants(string $namespace): array
    {
        $descendants = [];

        foreach ($this->children[$namespace] ?? [] as $child) {
            $descendants[] = $child;

            foreach ($this->getDescendants($child) as $descendant) {
                $descendants[] = $descendant;
            }
        }

        return $descendants;
    }

    /**
     * Returns all leaf namespaces.
     *
     * @return list<string>
     */
    public function getLeaves(): array
    {
        return array_keys($this->leafSet);
    }

    /**
     * Returns all non-leaf namespaces (parents).
     *
     * @return list<string>
     */
    public function getParentNamespaces(): array
    {
        return array_keys($this->children);
    }

    /**
     * Returns all namespaces (leaves + parents).
     *
     * @return list<string>
     */
    public function getAllNamespaces(): array
    {
        return array_keys($this->allNodes);
    }

    /**
     * Whether the namespace exists in the tree.
     */
    public function has(string $namespace): bool
    {
        return isset($this->allNodes[$namespace]);
    }
}
