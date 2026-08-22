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
 * `ClassLikeHandler` is deliberately not one of the table's handlers: entering
 * a class-like node is also what opens the `DependencyContext` its members are
 * attributed to, so it must run before generic per-node dispatch even exists,
 * not be looked up by it. {@see DependencyVisitor} holds and calls it directly.
 */
final class DependencyHandlerTable
{
    /** @var array<class-string<Node>, NodeDependencyHandlerInterface> */
    private readonly array $byNodeClass;

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
    }

    public function dispatch(Node $node, DependencyContext $context): void
    {
        ($this->byNodeClass[$node::class] ?? null)?->handle($node, $context);
    }
}
