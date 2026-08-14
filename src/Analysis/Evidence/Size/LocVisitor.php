<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;

/**
 * Visitor for LocCollector.
 *
 * Tracks class node positions (start/end lines) for class-level LOC calculation.
 * Anonymous classes are ignored.
 */
final class LocVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    private ?string $currentNamespace = null;

    /**
     * @var array<string, array{namespace: ?string, className: string, startLine: int, startFilePos: int, endLine: int}>
     */
    private array $classRanges = [];

    /** @var array<string, list<array{startLine: int, endLine: int}>> */
    private array $namespaceRanges = [];

    public function reset(): void
    {
        $this->currentNamespace = null;
        $this->classRanges = [];
        $this->namespaceRanges = [];
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
            $this->namespaceRanges[$this->currentNamespace][] = [
                'startLine' => $node->getStartLine(),
                'endLine' => $node->getEndLine(),
            ];

            return null;
        }

        if ($node instanceof Class_ && $node->name !== null) {
            $className = $node->name->toString();
            $fqn = $this->buildClassFqn($className);

            $this->classRanges[$fqn] = [
                'namespace' => $this->currentNamespace,
                'className' => $className,
                'startLine' => $node->getStartLine(),
                'startFilePos' => max(0, $node->getStartFilePos()),
                'endLine' => $node->getEndLine(),
            ];
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    /**
     * @return array<string, array{namespace: ?string, className: string, startLine: int, startFilePos: int, endLine: int}>
     */
    public function getClassRanges(): array
    {
        return $this->classRanges;
    }

    /**
     * @return array<string, list<array{startLine: int, endLine: int}>>
     */
    public function getNamespaceRanges(): array
    {
        return $this->namespaceRanges;
    }

    private function buildClassFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }
}
