<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Rfc;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;

/** Collects independent raw RFC facts for each named physical declaration. */
final class RfcVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /** @var array<int, RfcClassFacts> */
    private array $classes = [];

    private string $namespace = '';

    public function reset(): void
    {
        $this->classes = [];
        $this->namespace = '';
    }

    /** @return array<int, RfcClassFacts> */
    public function getClassesData(): array
    {
        return $this->classes;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
        } elseif ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
            $this->classes[$node->getStartFilePos()] = RfcClassFacts::fromNode($node, $this->namespace);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = '';
        }

        return null;
    }
}
