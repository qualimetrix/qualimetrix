<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use PhpParser\Node;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use Qualimetrix\Core\Dependency\DependencyType;

final readonly class CatchInstanceofHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [Catch_::class, Instanceof_::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        if ($node instanceof Catch_) {
            foreach ($node->types as $type) {
                $context->addDependency(
                    $context->getResolver()->resolve($type),
                    DependencyType::Catch_,
                    $type->getStartLine(),
                );
            }

            return;
        }

        if ($node instanceof Instanceof_) {
            if ($node->class instanceof Name && !$node->class->isSpecialClassName()) {
                $context->addDependency(
                    $context->getResolver()->resolve($node->class),
                    DependencyType::Instanceof_,
                    $node->getStartLine(),
                );
            }
        }
    }
}
