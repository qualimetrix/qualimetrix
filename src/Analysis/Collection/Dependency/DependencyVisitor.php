<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Collection\Dependency\Handler\CatchInstanceofHandler;
use Qualimetrix\Analysis\Collection\Dependency\Handler\ClassLikeHandler;
use Qualimetrix\Analysis\Collection\Dependency\Handler\DependencyContext;
use Qualimetrix\Analysis\Collection\Dependency\Handler\FunctionLikeHandler;
use Qualimetrix\Analysis\Collection\Dependency\Handler\InstantiationHandler;
use Qualimetrix\Analysis\Collection\Dependency\Handler\NodeDependencyHandlerInterface;
use Qualimetrix\Analysis\Collection\Dependency\Handler\PropertyHandler;
use Qualimetrix\Analysis\Collection\Dependency\Handler\StaticAccessHandler;
use Qualimetrix\Analysis\Collection\Dependency\Handler\TraitUseHandler;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
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
final class DependencyVisitor extends NodeVisitorAbstract
{
    private ?RelativePath $file = null;
    private ?string $currentClass = null;
    private ?DependencyContext $currentContext = null;

    /** @var list<Dependency> */
    private array $dependencies = [];

    private ClassLikeHandler $classLikeHandler;

    /** @var array<class-string<Node>, NodeDependencyHandlerInterface> */
    private array $dispatchTable;

    public function __construct(
        private readonly DependencyResolver $resolver,
    ) {
        $this->classLikeHandler = new ClassLikeHandler();
        $this->dispatchTable = $this->buildDispatchTable();
    }

    /**
     * Initializes the visitor for a new file (null clears the current file).
     */
    public function setFile(?RelativePath $file): void
    {
        $this->file = $file;
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
     * @return array<Dependency>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

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

        if ($this->file === null) {
            throw new LogicException('DependencyVisitor requires a relative file path before traversing declarations');
        }

        $this->currentContext = new DependencyContext(
            $this->resolver,
            $this->file,
            new DeclarationPath(
                SymbolPath::fromClassFqn($this->currentClass),
                $this->file,
                $node->getStartFilePos(),
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

        ($this->dispatchTable[$node::class] ?? null)?->handle($node, $this->currentContext);
    }

    /**
     * @return array<class-string<Node>, NodeDependencyHandlerInterface>
     */
    private function buildDispatchTable(): array
    {
        $handlers = [
            new TraitUseHandler(),
            new InstantiationHandler(),
            new StaticAccessHandler(),
            new CatchInstanceofHandler(),
            new PropertyHandler(),
            new FunctionLikeHandler(),
        ];

        $table = [];
        foreach ($handlers as $handler) {
            foreach ($handler::supportedNodeClasses() as $nodeClass) {
                $table[$nodeClass] = $handler;
            }
        }

        return $table;
    }
}
