<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Extracts parameter/return type hints and parameter attributes from any
 * function-like signature: class methods, closures, and arrow functions.
 *
 * `Closure` and `ArrowFunction` bodies are already traversed by the normal
 * node visitation (so `new`/static-call/etc. dependencies inside them were
 * already detected) — this handler covers the signature itself (`params`,
 * `returnType`, `attrGroups`), which `PhpParser\Node\FunctionLike` exposes
 * uniformly across all three node types.
 */
final readonly class FunctionLikeHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [ClassMethod::class, Closure::class, ArrowFunction::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        \assert($node instanceof FunctionLike);

        TypeDependencyHelper::processAttributes($node->getAttrGroups(), $node->getStartLine(), $context);

        foreach ($node->getParams() as $param) {
            if ($param->type !== null) {
                TypeDependencyHelper::processType($param->type, DependencyType::TypeHint, $context);
            }
            TypeDependencyHelper::processAttributes($param->attrGroups, $param->getStartLine(), $context);
        }

        $returnType = $node->getReturnType();
        if ($returnType !== null) {
            TypeDependencyHelper::processType($returnType, DependencyType::TypeHint, $context);
        }
    }
}
