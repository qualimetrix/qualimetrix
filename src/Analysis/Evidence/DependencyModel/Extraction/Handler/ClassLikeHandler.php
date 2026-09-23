<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Records what a class-like declares above itself: `extends`, `implements`,
 * attributes, and the interfaces PHP adds without their being written.
 *
 * Every enum implements `UnitEnum`, a backed one `BackedEnum` as well, and a
 * class or interface declaring `__toString()` is `Stringable`. They are
 * recorded as `implements` edges to PHP's own interfaces, which the coupling
 * views drop, so they reach declaration readers without moving a coupling
 * metric. A `__toString()` a class gets from a trait is not seen here: the
 * trait's body is another declaration.
 */
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
        // An anonymous class has no declaration identity of its own, so its
        // header edges (extends/implements/attributes) are recorded with the
        // enclosing class as their source. DependencyVisitor::consumeAnonymousClass()
        // marks $context accordingly for the duration of this call, and
        // DependencyContext::addDependency() reads that ambient state — see
        // Dependency::$describesNestedAnonymousClass.
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

        self::recordImplicitStringable($node, $context);
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

        self::recordImplicitStringable($node, $context);
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

        $context->addDependency('UnitEnum', DependencyType::Implements, $node->getStartLine());
        if ($node->scalarType !== null) {
            $context->addDependency('BackedEnum', DependencyType::Implements, $node->getStartLine());
        }

        TypeDependencyHelper::processAttributes($node->attrGroups, $node->getStartLine(), $context);
    }

    private static function recordImplicitStringable(ClassLike $node, DependencyContext $context): void
    {
        $toString = $node->getMethod('__tostring');
        if ($toString !== null) {
            $context->addDependency('Stringable', DependencyType::Implements, $toString->getStartLine());
        }
    }
}
