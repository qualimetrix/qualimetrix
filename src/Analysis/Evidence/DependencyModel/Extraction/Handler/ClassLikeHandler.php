<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Symbol\ClassType;

/**
 * Records class-like header edges and the facts declared by its direct body.
 *
 * Every enum still has implicit `UnitEnum` and, when backed, `BackedEnum`
 * declaration edges. Direct `__toString()` declarations and trait adaptations
 * that alias a method to that name are class-like declaration facts instead of
 * synthetic `Stringable` edges. This preserves a named declaration even when
 * it has no dependency edge and leaves inherited/trait closure to its reader.
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
        \assert($node instanceof ClassLike);
        if ($node->name !== null) {
            $context->recorder()->recordClassLike(ClassLikeDeclaration::of(
                $context->recorder()->declaration(),
                self::classType($node),
                $node->getMethod('__tostring') !== null,
                self::aliasesTraitMethodAsToString($node),
            ));
        }

        if ($node instanceof Class_) {
            $this->handleClass($node, $context);

            return;
        }

        if ($node instanceof Interface_) {
            $this->handleInterface($node, $context);

            return;
        }

        if ($node instanceof Trait_) {
            TypeDependencyHelper::processAttributes(
                $node->attrGroups,
                $node->getStartLine(),
                $context->classHeaderAttributeSite(),
                $context,
            );

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
        // DependencyContext's class-like and attribute operations read that
        // ambient state — see Dependency::$describesNestedAnonymousClass.
        if ($node->extends !== null) {
            $context->addClassLikeDependency(
                $context->getResolver()->resolve($node->extends),
                DependencyType::Extends,
                $node->extends->getStartLine(),
            );
        }

        foreach ($node->implements as $interface) {
            $context->addClassLikeDependency(
                $context->getResolver()->resolve($interface),
                DependencyType::Implements,
                $interface->getStartLine(),
            );
        }

        TypeDependencyHelper::processAttributes(
            $node->attrGroups,
            $node->getStartLine(),
            $node->name === null ? AttributeSite::NestedClass : $context->classHeaderAttributeSite(),
            $context,
        );
    }

    private function handleInterface(Interface_ $node, DependencyContext $context): void
    {
        foreach ($node->extends as $parent) {
            $context->addInterfaceParent($context->getResolver()->resolve($parent), $parent->getStartLine());
        }

        TypeDependencyHelper::processAttributes(
            $node->attrGroups,
            $node->getStartLine(),
            $context->classHeaderAttributeSite(),
            $context,
        );
    }

    private function handleEnum(Enum_ $node, DependencyContext $context): void
    {
        foreach ($node->implements as $interface) {
            $context->addClassLikeDependency(
                $context->getResolver()->resolve($interface),
                DependencyType::Implements,
                $interface->getStartLine(),
            );
        }

        $context->addClassLikeDependency('UnitEnum', DependencyType::Implements, $node->getStartLine());
        if ($node->scalarType !== null) {
            $context->addClassLikeDependency('BackedEnum', DependencyType::Implements, $node->getStartLine());
        }

        TypeDependencyHelper::processAttributes(
            $node->attrGroups,
            $node->getStartLine(),
            $context->classHeaderAttributeSite(),
            $context,
        );
    }

    private static function classType(ClassLike $node): ClassType
    {
        return match (true) {
            $node instanceof Class_ => ClassType::Class_,
            $node instanceof Interface_ => ClassType::Interface_,
            $node instanceof Trait_ => ClassType::Trait_,
            $node instanceof Enum_ => ClassType::Enum_,
            default => throw new LogicException('Unsupported class-like declaration'),
        };
    }

    private static function aliasesTraitMethodAsToString(ClassLike $node): bool
    {
        foreach ($node->stmts as $statement) {
            if (!$statement instanceof TraitUse) {
                continue;
            }
            foreach ($statement->adaptations as $adaptation) {
                if ($adaptation instanceof Alias
                    && $adaptation->newName !== null
                    && strcasecmp($adaptation->newName->toString(), '__toString') === 0
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
