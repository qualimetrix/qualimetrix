<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\ClassLikeHandler;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\DependencyContext;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler\DependencyHandlerTable;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationKey;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Visitor that collects all class dependencies from AST.
 *
 * Detects all 14 dependency types:
 * - Extends, Implements, TraitUse
 * - New, StaticCall, StaticPropertyFetch, ClassConstFetch
 * - TypeHint (params, returns, properties — including closure and arrow
 *   function signatures, not just class methods)
 * - Catch, Instanceof
 * - Attribute (including attributes on closure/arrow function parameters)
 * - PropertyType
 * - IntersectionType, UnionType
 *
 * Note: closures/arrow functions declared outside any enclosing class (e.g.
 * at file top level, backing a "global function") have no owning symbol —
 * their dependencies are not tracked, matching how top-level `function`
 * declarations are handled. See `FunctionLikeHandler` for signature extraction.
 */
final class DependencyVisitor extends NodeVisitorAbstract implements DependencyTraversalParticipantInterface
{
    private ?RelativePath $file = null;
    private ?FileDeclarationIndex $declarationIndex = null;
    private ?string $currentClass = null;
    private ?DependencyContext $currentContext = null;

    /** @var list<Dependency> */
    private array $dependencies = [];

    private readonly DependencyHandlerTable $handlers;

    /**
     * Held and invoked directly rather than through `$handlers` — see
     * {@see DependencyHandlerTable} for why a class-like node cannot be
     * looked up by the generic dispatch table.
     */
    private readonly ClassLikeHandler $classLikeHandler;

    public function __construct(
        ?DependencyResolver $resolver = null,
        ?DependencyHandlerTable $handlers = null,
        ?ClassLikeHandler $classLikeHandler = null,
    ) {
        $this->resolver = $resolver ?? new DependencyResolver();
        $this->handlers = $handlers ?? new DependencyHandlerTable();
        $this->classLikeHandler = $classLikeHandler ?? new ClassLikeHandler();
    }

    /**
     * Initializes the visitor for a new file.
     */
    public function beginFile(RelativePath $file, FileDeclarationIndex $index): void
    {
        $this->file = $file;
        $this->declarationIndex = $index;
        $this->reset();
    }

    /**
     * Resets the visitor state between files.
     *
     * Called automatically by setFile(), but can also be called directly
     * when reusing the visitor for multiple files in the same traverser.
     */
    public function reset(): void
    {
        $this->dependencies = [];
        $this->currentClass = null;
        $this->currentContext = null;
        $this->resolver->reset();
    }

    /**
     * Returns all collected dependencies.
     *
     * @return list<Dependency>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    private readonly DependencyResolver $resolver;

    public function enterNode(Node $node): ?int
    {
        if ($this->consumeNamespaceOrImport($node)) {
            return null;
        }

        if ($this->enterNamedClassLike($node)) {
            return null;
        }

        if ($this->consumeAnonymousClass($node)) {
            return null;
        }

        $this->dispatchInCurrentContext($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Only reset class scope for named classes (skip anonymous classes —
        // they don't set currentClass on enter, so leaving them shouldn't clear it)
        if ($node instanceof ClassLike && $node->name !== null) {
            if ($this->currentContext !== null) {
                array_push($this->dependencies, ...$this->currentContext->getDependencies());
            }
            $this->currentClass = null;
            $this->currentContext = null;
        }

        return null;
    }

    private function consumeNamespaceOrImport(Node $node): bool
    {
        if ($node instanceof Namespace_) {
            $this->resolver->reset();
            $this->resolver->setNamespace($node->name?->toString());

            return true;
        }

        if ($node instanceof Use_) {
            $this->resolver->addUseStatement($node);

            return true;
        }

        if ($node instanceof GroupUse) {
            $this->resolver->addGroupUseStatement($node);

            return true;
        }

        return false;
    }

    private function enterNamedClassLike(Node $node): bool
    {
        if (!$node instanceof ClassLike || $node->name === null) {
            return false;
        }

        $className = $node->name->toString();
        $this->currentClass = $this->resolver->getNamespace() !== null
            ? $this->resolver->getNamespace() . '\\' . $className
            : $className;

        if ($this->file === null || $this->declarationIndex === null) {
            throw new LogicException('DependencyVisitor requires a relative file path and declaration index before traversing declarations');
        }

        $logical = SymbolPath::fromClassFqn($this->currentClass);
        $this->currentContext = new DependencyContext(
            $this->resolver,
            $this->file,
            DeclarationPath::of(
                $logical,
                $this->file,
                $this->declarationIndex->ordinalOf(DeclarationKey::forLogical($logical), $node->getStartFilePos()),
            ),
        );
        $this->classLikeHandler->handle($node, $this->currentContext);

        return true;
    }

    private function consumeAnonymousClass(Node $node): bool
    {
        if (!$node instanceof Class_ || $node->name !== null || $this->currentContext === null) {
            return false;
        }

        $this->classLikeHandler->handle($node, $this->currentContext);

        return true;
    }

    private function dispatchInCurrentContext(Node $node): void
    {
        if ($this->currentClass === null || $this->currentContext === null) {
            return;
        }

        $this->handlers->dispatch($node, $this->currentContext);
    }
}
