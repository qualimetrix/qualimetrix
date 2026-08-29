<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;

/**
 * Visitor for counting classes, interfaces, traits, enums, and standalone functions.
 *
 * Ignores anonymous classes (Class_ nodes without a name).
 * Counts only standalone functions (not class methods).
 */
final class ClassCountVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    private string $currentNamespace = '';

    /** @var array<string, array{line: int, 'size.class-count': int, 'size.abstract-class-count': int, 'size.interface-count': int, 'size.trait-count': int, 'size.enum-count': int, 'size.implementing-enum-count': int, 'size.function-count': int}> */
    private array $namespaceCounts = [];
    private int $classCount = 0;
    private int $abstractClassCount = 0;
    private int $interfaceCount = 0;
    private int $traitCount = 0;
    private int $enumCount = 0;
    private int $implementingEnumCount = 0;
    private int $functionCount = 0;

    public function reset(): void
    {
        $this->currentNamespace = '';
        $this->namespaceCounts = [];
        $this->classCount = 0;
        $this->abstractClassCount = 0;
        $this->interfaceCount = 0;
        $this->traitCount = 0;
        $this->enumCount = 0;
        $this->implementingEnumCount = 0;
        $this->functionCount = 0;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
            $this->ensureNamespace($this->currentNamespace, $node->getStartLine());

            return null;
        }

        // Count named classes only (skip anonymous classes)
        if ($node instanceof Class_ && $node->name !== null) {
            $this->ensureNamespace($this->currentNamespace, $node->getStartLine());
            ++$this->classCount;
            $counts = $this->namespaceCounts[$this->currentNamespace];
            ++$counts['size.class-count'];

            if ($node->isAbstract()) {
                ++$this->abstractClassCount;
                ++$counts['size.abstract-class-count'];
            }
            $this->namespaceCounts[$this->currentNamespace] = $counts;

            return null;
        }

        if ($node instanceof Interface_) {
            $this->ensureNamespace($this->currentNamespace, $node->getStartLine());
            ++$this->interfaceCount;
            $counts = $this->namespaceCounts[$this->currentNamespace];
            ++$counts['size.interface-count'];
            $this->namespaceCounts[$this->currentNamespace] = $counts;

            return null;
        }

        if ($node instanceof Trait_) {
            $this->ensureNamespace($this->currentNamespace, $node->getStartLine());
            ++$this->traitCount;
            $counts = $this->namespaceCounts[$this->currentNamespace];
            ++$counts['size.trait-count'];
            $this->namespaceCounts[$this->currentNamespace] = $counts;

            return null;
        }

        if ($node instanceof Enum_) {
            $this->ensureNamespace($this->currentNamespace, $node->getStartLine());
            ++$this->enumCount;
            $counts = $this->namespaceCounts[$this->currentNamespace];
            ++$counts['size.enum-count'];

            if ($node->implements !== []) {
                ++$this->implementingEnumCount;
                ++$counts['size.implementing-enum-count'];
            }
            $this->namespaceCounts[$this->currentNamespace] = $counts;

            return null;
        }

        if ($node instanceof Function_) {
            $this->ensureNamespace($this->currentNamespace, $node->getStartLine());
            ++$this->functionCount;
            $counts = $this->namespaceCounts[$this->currentNamespace];
            ++$counts['size.function-count'];
            $this->namespaceCounts[$this->currentNamespace] = $counts;

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = '';
        }

        return null;
    }

    /**
     * A file without an explicit namespace or countable symbols still belongs
     * to the global namespace. Keep the structural metric family present so
     * it can be merged with contributions from other namespace providers.
     *
     * @param Node[] $nodes
     */
    public function afterTraverse(array $nodes): ?array
    {
        if ($this->namespaceCounts === []) {
            $this->ensureNamespace('', $nodes === [] ? 1 : $nodes[0]->getStartLine());
        }

        return null;
    }

    /**
     * @return array<string, array{line: int, 'size.class-count': int, 'size.abstract-class-count': int, 'size.interface-count': int, 'size.trait-count': int, 'size.enum-count': int, 'size.implementing-enum-count': int, 'size.function-count': int}>
     */
    public function getNamespaceCounts(): array
    {
        return $this->namespaceCounts;
    }

    private function ensureNamespace(string $namespace, int $line): void
    {
        $this->namespaceCounts[$namespace] ??= [
            'line' => $line,
            'size.class-count' => 0,
            'size.abstract-class-count' => 0,
            'size.interface-count' => 0,
            'size.trait-count' => 0,
            'size.enum-count' => 0,
            'size.implementing-enum-count' => 0,
            'size.function-count' => 0,
        ];
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

    /**
     * Enums declaring `implements` are a distinct class of declaration for
     * Abstractness: they are substitutable implementations of a named
     * contract, while a bare literal enumeration is not.
     */
    public function getImplementingEnumCount(): int
    {
        return $this->implementingEnumCount;
    }

    public function getFunctionCount(): int
    {
        return $this->functionCount;
    }
}
