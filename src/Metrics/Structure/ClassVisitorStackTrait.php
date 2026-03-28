<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;

/**
 * Shared scaffolding for structure visitors that track namespace/class context.
 *
 * Provides namespace tracking, class stack management, FQN building,
 * and property name extraction. Used by LcomVisitor and TccLccVisitor.
 *
 * Expects the using class to call resetClassVisitorStack() in its reset() method
 * and handleNamespaceEnter/Leave + pushClass/popClass from enterNode/leaveNode.
 */
trait ClassVisitorStackTrait
{
    private ?string $currentNamespace = null;

    /**
     * Stack of class contexts (to handle nested/anonymous classes).
     *
     * @var list<string|null>
     */
    private array $classStack = [];

    protected function handleNamespaceEnter(Node\Stmt\Namespace_ $node): void
    {
        $this->currentNamespace = $node->name?->toString() ?? '';
    }

    protected function handleNamespaceLeave(): void
    {
        $this->currentNamespace = null;
    }

    protected function pushClass(?string $className): void
    {
        $this->classStack[] = $className;
    }

    protected function popClass(): void
    {
        array_pop($this->classStack);
    }

    /**
     * Returns current class name or null if inside anonymous class or no class.
     */
    protected function getCurrentClass(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)];
    }

    protected function buildClassFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }

    /**
     * Extract property name from PropertyFetch node.
     */
    protected function extractPropertyName(PropertyFetch $node): ?string
    {
        if ($node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        // Dynamic property access like $this->$var - skip
        return null;
    }

    /**
     * Extracts class name from class-like nodes.
     * Returns null for anonymous classes or non-class-like nodes.
     *
     * Note: Both LCOM and TCC/LCC call this only for Class_ nodes
     * (interfaces and enums are filtered upstream), so the match
     * against Class_ is the only effective branch.
     */
    protected function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Class_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    protected function resetClassVisitorStack(): void
    {
        $this->currentNamespace = null;
        $this->classStack = [];
    }
}
