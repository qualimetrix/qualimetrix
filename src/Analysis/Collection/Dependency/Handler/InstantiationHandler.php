<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use Qualimetrix\Core\Dependency\DependencyType;

final readonly class InstantiationHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [New_::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        \assert($node instanceof New_);

        if ($node->class instanceof Name) {
            // self, static, parent are special class names — not real dependencies
            if ($node->class->isSpecialClassName()) {
                return;
            }

            $context->addDependency(
                $context->getResolver()->resolve($node->class),
                DependencyType::New_,
                $node->getStartLine(),
            );
        }
    }
}
