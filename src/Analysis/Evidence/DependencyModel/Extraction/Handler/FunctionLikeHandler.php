<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Extracts parameter/return type hints and parameter attributes from any
 * function-like signature: class methods, closures, and arrow functions.
 *
 * `Closure` and `ArrowFunction` bodies are already traversed by the normal
 * node visitation (so `new`/static-call/etc. dependencies inside them were
 * already detected) — this handler covers the signature itself (`params`,
 * `returnType`, `attrGroups`), which `PhpParser\Node\FunctionLike` exposes
 * uniformly across all three node types.
 */
final readonly class FunctionLikeHandler implements NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array
    {
        return [ClassMethod::class, Closure::class, ArrowFunction::class, Function_::class, PropertyHook::class];
    }

    public function handle(Node $node, DependencyContext $context): void
    {
        \assert($node instanceof FunctionLike);

        TypeDependencyHelper::processAttributes(
            $node->getAttrGroups(),
            $node->getStartLine(),
            self::attributeSite($node),
            $context,
        );
        foreach ($node->getParams() as $parameter) {
            self::processParameter($node, $parameter, $context);
        }
        $returnType = $node->getReturnType();
        if ($returnType !== null) {
            TypeDependencyHelper::processType($returnType, DependencyType::TypeHint, $context);
        }
    }

    private static function attributeSite(FunctionLike $node): AttributeSite
    {
        return match (true) {
            $node instanceof ClassMethod => AttributeSite::Method,
            $node instanceof Function_ => AttributeSite::NestedFunction,
            $node instanceof PropertyHook => AttributeSite::PropertyHook,
            default => AttributeSite::NestedCallable,
        };
    }

    private static function processParameter(FunctionLike $owner, Param $parameter, DependencyContext $context): void
    {
        if ($parameter->type !== null) {
            TypeDependencyHelper::processType(
                $parameter->type,
                $parameter->isPromoted() ? DependencyType::PropertyType : DependencyType::TypeHint,
                $context,
            );
        }
        TypeDependencyHelper::processAttributes(
            $parameter->attrGroups,
            $parameter->getStartLine(),
            self::parameterSite($owner, $parameter),
            $context,
        );
    }

    private static function parameterSite(FunctionLike $owner, Param $parameter): AttributeSite
    {
        return match (true) {
            $owner instanceof PropertyHook => AttributeSite::HookParameter,
            $owner instanceof ClassMethod && $parameter->isPromoted() => AttributeSite::PromotedParameter,
            $owner instanceof ClassMethod => AttributeSite::Parameter,
            $owner instanceof Function_ => AttributeSite::NestedFunction,
            default => AttributeSite::NestedCallable,
        };
    }
}
