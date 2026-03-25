<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Size;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

/**
 * Visitor for counting classes, interfaces, traits, enums, and standalone functions.
 *
 * Ignores anonymous classes (Class_ nodes without a name).
 * Counts only standalone functions (not class methods).
 */
final class ClassCountVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    private int $classCount = 0;
    private int $abstractClassCount = 0;
    private int $interfaceCount = 0;
    private int $traitCount = 0;
    private int $enumCount = 0;
    private int $functionCount = 0;

    public function reset(): void
    {
        $this->classCount = 0;
        $this->abstractClassCount = 0;
        $this->interfaceCount = 0;
        $this->traitCount = 0;
        $this->enumCount = 0;
        $this->functionCount = 0;
    }

    public function enterNode(Node $node): ?int
    {
        // Count named classes only (skip anonymous classes)
        if ($node instanceof Class_ && $node->name !== null) {
            ++$this->classCount;

            if ($node->isAbstract()) {
                ++$this->abstractClassCount;
            }

            return null;
        }

        if ($node instanceof Interface_) {
            ++$this->interfaceCount;

            return null;
        }

        if ($node instanceof Trait_) {
            ++$this->traitCount;

            return null;
        }

        if ($node instanceof Enum_) {
            ++$this->enumCount;

            return null;
        }

        if ($node instanceof Function_) {
            ++$this->functionCount;

            return null;
        }

        return null;
    }

    public function getClassCount(): int
    {
        return $this->classCount;
    }

    public function getAbstractClassCount(): int
    {
        return $this->abstractClassCount;
    }

    public function getInterfaceCount(): int
    {
        return $this->interfaceCount;
    }

    public function getTraitCount(): int
    {
        return $this->traitCount;
    }

    public function getEnumCount(): int
    {
        return $this->enumCount;
    }

    public function getFunctionCount(): int
    {
        return $this->functionCount;
    }
}
