<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor for collecting inheritance information for DIT calculation.
 *
 * For each class, tracks:
 * - Class FQN
 * - Parent class FQN (if any)
 * - Namespace, class name, and line number
 *
 * Only tracks named classes (anonymous classes are ignored).
 * Interfaces, traits, and enums don't participate in inheritance depth.
 */
final class InheritanceDepthVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /**
     * Map of class FQN => InheritanceClassInfo.
     *
     * @var array<string, InheritanceClassInfo>
     */
    private array $classInfo = [];

    private ?string $currentNamespace = null;

    public function reset(): void
    {
        $this->classInfo = [];
        $this->currentNamespace = null;
    }

    /**
     * @return array<string, InheritanceClassInfo>
     */
    public function getClassInfo(): array
    {
        return $this->classInfo;
    }

    /**
     * @return array<string, string|null>
     */
    public function getClassParents(): array
    {
        $result = [];
        foreach ($this->classInfo as $fqn => $info) {
            $result[$fqn] = $info->parentFqn;
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';

            return null;
        }

        // Track class inheritance
        if ($node instanceof Class_ && $node->name !== null) {
            $className = $node->name->toString();
            $classFqn = $this->buildFqn($className);

            $parentFqn = null;
            if ($node->extends !== null) {
                $parentFqn = $this->resolveClassName($node->extends);
            }

            $this->classInfo[$classFqn] = new InheritanceClassInfo(
                namespace: $this->currentNamespace,
                className: $className,
                line: $node->getStartLine(),
                parentFqn: $parentFqn,
            );

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    /**
     * Resolve class name to FQN.
     */
    private function resolveClassName(Node\Name $name): string
    {
        // If fully qualified, use as-is
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        // Otherwise, prepend current namespace
        $className = $name->toString();

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }

    private function buildFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }
}
