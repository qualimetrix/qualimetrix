<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics;

use PhpParser\Node;

/**
 * Provides common FQN-building and class-like node detection methods
 * for metric visitors that track methods, functions, and closures.
 *
 * Expects the using class to declare these properties:
 * - ?string $currentNamespace
 * - ?string $currentClass
 * - int $closureCounter
 */
trait VisitorMethodTrackingTrait
{
    private function buildMethodFqn(string $methodName): string
    {
        $parts = [];

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            $parts[] = $this->currentNamespace;
        }

        if ($this->currentClass !== null) {
            if ($parts !== []) {
                $parts[] = '\\';
            }
            $parts[] = $this->currentClass;
        }

        $parts[] = '::';
        $parts[] = $methodName;

        return implode('', $parts);
    }

    private function buildFunctionFqn(string $functionName): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $functionName;
        }

        return $functionName;
    }

    private function buildClosureFqn(): string
    {
        $parts = [];

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            $parts[] = $this->currentNamespace;
        }

        if ($this->currentClass !== null) {
            if ($parts !== []) {
                $parts[] = '\\';
            }
            $parts[] = $this->currentClass;
        }

        $parts[] = '::';
        $parts[] = '{closure#' . $this->closureCounter . '}';

        return implode('', $parts);
    }

    /**
     * Extracts class name from class-like nodes (class, interface, trait, enum).
     * Returns null for anonymous classes or non-class-like nodes.
     */
    private function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Node\Stmt\Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Interface_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Trait_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Enum_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    /**
     * Checks if node is a class-like type (class, interface, trait, enum).
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_;
    }
}
