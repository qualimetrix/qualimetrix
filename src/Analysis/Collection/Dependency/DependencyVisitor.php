<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Analysis\Collection\Dependency\Handler\CatchInstanceofHandler;
use AiMessDetector\Analysis\Collection\Dependency\Handler\ClassLikeHandler;
use AiMessDetector\Analysis\Collection\Dependency\Handler\DependencyContext;
use AiMessDetector\Analysis\Collection\Dependency\Handler\InstantiationHandler;
use AiMessDetector\Analysis\Collection\Dependency\Handler\MethodHandler;
use AiMessDetector\Analysis\Collection\Dependency\Handler\NodeDependencyHandlerInterface;
use AiMessDetector\Analysis\Collection\Dependency\Handler\PropertyHandler;
use AiMessDetector\Analysis\Collection\Dependency\Handler\StaticAccessHandler;
use AiMessDetector\Analysis\Collection\Dependency\Handler\TraitUseHandler;
use AiMessDetector\Core\Dependency\Dependency;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that collects all class dependencies from AST.
 *
 * Detects all 14 dependency types:
 * - Extends, Implements, TraitUse
 * - New, StaticCall, StaticPropertyFetch, ClassConstFetch
 * - TypeHint (params, returns, properties)
 * - Catch, Instanceof
 * - Attribute
 * - PropertyType
 * - IntersectionType, UnionType
 */
final class DependencyVisitor extends NodeVisitorAbstract
{
    private string $file = '';
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
     * Initializes the visitor for a new file.
     */
    public function setFile(string $file): void
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
        if ($node instanceof Namespace_) {
            // Reset imports when entering a new namespace block to prevent
            // use-imports from one namespace leaking into another
            $this->resolver->reset();
            $this->resolver->setNamespace($node->name?->toString());

            return null;
        }

        if ($node instanceof Use_) {
            $this->resolver->addUseStatement($node);

            return null;
        }

        if ($node instanceof GroupUse) {
            $this->resolver->addGroupUseStatement($node);

            return null;
        }

        $className = $this->extractClassLikeName($node);
        if ($className !== null) {
            $this->currentClass = $this->resolver->getNamespace() !== null
                ? $this->resolver->getNamespace() . '\\' . $className
                : $className;

            $this->currentContext = new DependencyContext(
                $this->resolver,
                $this->file,
                $this->currentClass,
            );

            $this->classLikeHandler->handle($node, $this->currentContext);

            return null;
        }

        if ($this->currentClass === null || $this->currentContext === null) {
            return null;
        }

        $handler = $this->dispatchTable[$node::class] ?? null;
        if ($handler !== null) {
            $handler->handle($node, $this->currentContext);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Only reset class scope for named classes (skip anonymous classes —
        // they don't set currentClass on enter, so leaving them shouldn't clear it)
        if ($this->isClassLikeNode($node) && $this->extractClassLikeName($node) !== null) {
            if ($this->currentContext !== null) {
                array_push($this->dependencies, ...$this->currentContext->getDependencies());
            }
            $this->currentClass = null;
            $this->currentContext = null;
        }

        return null;
    }

    private function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Interface_ && $node->name !== null => $node->name->toString(),
            $node instanceof Trait_ && $node->name !== null => $node->name->toString(),
            $node instanceof Enum_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
            || $node instanceof Enum_;
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
            new MethodHandler(),
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
