<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency\Handler;

use AiMessDetector\Core\Dependency\DependencyType;
use PhpParser\Node;
use PhpParser\Node\Stmt\TraitUse;

final readonly class TraitUseHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [TraitUse::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        \assert($node instanceof TraitUse);

        foreach ($node->traits as $trait) {
            $context->addDependency(
                $context->getResolver()->resolve($trait),
                DependencyType::TraitUse,
                $trait->getStartLine(),
            );
        }
    }
}
