<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use Qualimetrix\Core\Dependency\DependencyType;

final readonly class MethodHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [ClassMethod::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        \assert($node instanceof ClassMethod);

        TypeDependencyHelper::processAttributes($node->attrGroups, $node->getStartLine(), $context);

        foreach ($node->params as $param) {
            if ($param->type !== null) {
                TypeDependencyHelper::processType($param->type, DependencyType::TypeHint, $context);
            }
            TypeDependencyHelper::processAttributes($param->attrGroups, $param->getStartLine(), $context);
        }

        if ($node->returnType !== null) {
            TypeDependencyHelper::processType($node->returnType, DependencyType::TypeHint, $context);
        }
    }
}
