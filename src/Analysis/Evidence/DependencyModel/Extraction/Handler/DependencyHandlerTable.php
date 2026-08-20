<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use PhpParser\Node;

/**
 * Which kind of node produces dependency edges, and who extracts them.
 *
 * That list changes when a new kind of dependency is recognised; walking a file
 * and collecting the edges does not. Keeping them apart also keeps the visitor
 * from naming every handler it never calls directly.
 *
 * @qmx-ignore design.data-class -- A routing table: both operations look up the handler for a node and pass the node to it, which the metric reads as a class that only hands its contents out.
 */
final class DependencyHandlerTable
{
    /** @var array<class-string<Node>, NodeDependencyHandlerInterface> */
    private readonly array $byNodeClass;

    private readonly ClassLikeHandler $classLikeHandler;

    public function __construct()
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

        $this->byNodeClass = $table;
        $this->classLikeHandler = new ClassLikeHandler();
    }

    public function dispatch(Node $node, DependencyContext $context): void
    {
        ($this->byNodeClass[$node::class] ?? null)?->handle($node, $context);
    }

    /**
     * A class-like node is handled on entry rather than by dispatch, because
     * entering it is also what opens the context its members are attributed to.
     */
    public function handleClassLike(Node $node, DependencyContext $context): void
    {
        $this->classLikeHandler->handle($node, $context);
    }
}
