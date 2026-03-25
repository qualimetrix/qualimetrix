<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use Qualimetrix\Core\Dependency\DependencyType;

final readonly class PropertyHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [Property::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        \assert($node instanceof Property);

        if ($node->type !== null) {
            TypeDependencyHelper::processType($node->type, DependencyType::PropertyType, $context);
        }

        TypeDependencyHelper::processAttributes($node->attrGroups, $node->getStartLine(), $context);
    }
}
