<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use Qualimetrix\Core\Dependency\DependencyType;

final readonly class StaticAccessHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [StaticCall::class, StaticPropertyFetch::class, ClassConstFetch::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        if ($node instanceof StaticCall) {
            $this->processStaticAccess($node->class, DependencyType::StaticCall, $node->getStartLine(), $context);

            return;
        }

        if ($node instanceof StaticPropertyFetch) {
            $this->processStaticAccess($node->class, DependencyType::StaticPropertyFetch, $node->getStartLine(), $context);

            return;
        }

        if ($node instanceof ClassConstFetch) {
            $this->processStaticAccess($node->class, DependencyType::ClassConstFetch, $node->getStartLine(), $context);
        }
    }

    private function processStaticAccess(Node $class, DependencyType $type, int $line, DependencyContext $context): void
    {
        if (!$class instanceof Name) {
            return;
        }

        if (TypeDependencyHelper::isSelfOrParent($class->toString())) {
            return;
        }

        $context->addDependency(
            $context->getResolver()->resolve($class),
            $type,
            $line,
        );
    }
}
