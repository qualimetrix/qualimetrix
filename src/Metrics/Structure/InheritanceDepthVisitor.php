<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

/**
 * Visitor for collecting inheritance information for DIT calculation.
 *
 * For each class, tracks:
 * - Class FQN
 * - Parent class FQN (if any)
 * - Namespace, class name, and line number
 * - Use imports for proper name resolution
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

    /**
     * Map of alias/short name => FQN from `use` statements in the current namespace.
     *
     * @var array<string, string>
     */
    private array $useImports = [];

    public function reset(): void
    {
        $this->classInfo = [];
        $this->currentNamespace = null;
        $this->useImports = [];
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
            $this->useImports = [];

            return null;
        }

        // Track use imports (use Foo\Bar, use Foo\Bar as Baz)
        if ($node instanceof Use_ && $node->type === Use_::TYPE_NORMAL) {
            foreach ($node->uses as $use) {
                $alias = $use->getAlias()->toString();
                $fqn = $use->name->toString();
                $this->useImports[$alias] = $fqn;
            }

            return null;
        }

        // Track group use imports (use Foo\{Bar, Baz as Qux})
        if ($node instanceof GroupUse && $node->type === Use_::TYPE_NORMAL) {
            $prefix = $node->prefix->toString();

            foreach ($node->uses as $use) {
                if ($use->type === Use_::TYPE_NORMAL || $use->type === Use_::TYPE_UNKNOWN) {
                    $alias = $use->getAlias()->toString();
                    $fqn = $prefix . '\\' . $use->name->toString();
                    $this->useImports[$alias] = $fqn;
                }
            }

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
            $this->useImports = [];
        }

        return null;
    }

    /**
     * Resolve class name to FQN.
     *
     * Resolution order:
     * 1. Fully qualified names (\Foo\Bar) — use as-is
     * 2. Names matching a `use` import — resolve via import map
     * 3. Unqualified/qualified names — prepend current namespace
     */
    private function resolveClassName(Node\Name $name): string
    {
        // If fully qualified, use as-is
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        $className = $name->toString();

        // Check use imports: for "Foo\Bar", the first part "Foo" might be an alias
        $parts = explode('\\', $className);
        $firstPart = $parts[0];

        if (isset($this->useImports[$firstPart])) {
            if (\count($parts) === 1) {
                // Simple name like "BaseAlias" -> resolved FQN
                return $this->useImports[$firstPart];
            }

            // Qualified name like "Sub\Class" where "Sub" is an import
            $remaining = \array_slice($parts, 1);

            return $this->useImports[$firstPart] . '\\' . implode('\\', $remaining);
        }

        // Otherwise, prepend current namespace
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
