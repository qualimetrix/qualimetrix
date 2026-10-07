<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\EnumCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

final readonly class ClassMemberDeclarationHandler implements NodeDependencyHandlerInterface
{
    /** @return list<class-string<Node>> */
    public static function supportedNodeClasses(): array
    {
        return [ClassConst::class, EnumCase::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        if ($node instanceof ClassConst) {
            if ($node->type !== null) {
                TypeDependencyHelper::processType($node->type, DependencyType::ConstantType, $context);
            }
            TypeDependencyHelper::processAttributes(
                $node->attrGroups,
                $node->getStartLine(),
                AttributeSite::ClassConstant,
                $context,
            );

            return;
        }

        \assert($node instanceof EnumCase);
        TypeDependencyHelper::processAttributes(
            $node->attrGroups,
            $node->getStartLine(),
            AttributeSite::EnumCase,
            $context,
        );
    }
}
