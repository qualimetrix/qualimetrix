<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency\Handler;

use AiMessDetector\Core\Dependency\DependencyType;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

final readonly class ClassLikeHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [Class_::class, Interface_::class, Trait_::class, Enum_::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        if ($node instanceof Class_) {
            $this->handleClass($node, $context);

            return;
        }

        if ($node instanceof Interface_) {
            $this->handleInterface($node, $context);

            return;
        }

        if ($node instanceof Trait_) {
            TypeDependencyHelper::processAttributes($node->attrGroups, $node->getStartLine(), $context);

            return;
        }

        if ($node instanceof Enum_) {
            $this->handleEnum($node, $context);
        }
    }

    private function handleClass(Class_ $node, DependencyContext $context): void
    {
        if ($node->extends !== null) {
            $context->addDependency(
                $context->getResolver()->resolve($node->extends),
                DependencyType::Extends,
                $node->extends->getStartLine(),
            );
        }

        foreach ($node->implements as $interface) {
            $context->addDependency(
                $context->getResolver()->resolve($interface),
                DependencyType::Implements,
                $interface->getStartLine(),
            );
        }

        TypeDependencyHelper::processAttributes($node->attrGroups, $node->getStartLine(), $context);
    }

    private function handleInterface(Interface_ $node, DependencyContext $context): void
    {
        foreach ($node->extends as $parent) {
            $context->addDependency(
                $context->getResolver()->resolve($parent),
                DependencyType::Extends,
                $parent->getStartLine(),
            );
        }

        TypeDependencyHelper::processAttributes($node->attrGroups, $node->getStartLine(), $context);
    }

    private function handleEnum(Enum_ $node, DependencyContext $context): void
    {
        foreach ($node->implements as $interface) {
            $context->addDependency(
                $context->getResolver()->resolve($interface),
                DependencyType::Implements,
                $interface->getStartLine(),
            );
        }

        TypeDependencyHelper::processAttributes($node->attrGroups, $node->getStartLine(), $context);
    }
}
