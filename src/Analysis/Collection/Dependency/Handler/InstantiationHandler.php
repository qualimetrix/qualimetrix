<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency\Handler;

use AiMessDetector\Core\Dependency\DependencyType;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;

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
            $context->addDependency(
                $context->getResolver()->resolve($node->class),
                DependencyType::New_,
                $node->getStartLine(),
            );
        }
    }
}
