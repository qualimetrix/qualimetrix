<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;

/** Captures declaration and own instance-call facts for class cohesion, keeping deferred calls apart. */
final class CohesionAstFacts
{
    public static function captureMember(TraitUse|Property $node, ?LcomClassData $data): void
    {
        if ($data === null) {
            return;
        }
        if ($node instanceof TraitUse) {
            $data->declaration()->addTraitUse(CohesionTraitUses::fromNode($node));

            return;
        }
        if (!$node->isStatic()) {
            $data->declaration()->incrementInstancePropertyCount(\count($node->props));
        }
    }

    public static function captureMethod(ClassMethod $node, ?LcomClassData $data): void
    {
        if ($data === null) {
            return;
        }
        $name = $node->name->toString();
        $visibility = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
        $data->declaration()->declareMethod($name, $visibility, $node->isAbstract());
        if (strtolower($name) !== '__construct') {
            return;
        }
        foreach ($node->params as $param) {
            if ($param->flags !== 0) {
                $data->declaration()->incrementInstancePropertyCount();
            }
        }
    }

    /** A first-class callable is a deferred call: it only names the method for later invocation. */
    public static function captureCall(Node $node, LcomClassData $data, string $caller): void
    {
        $callee = self::calleeName($node);
        if ($callee === null) {
            return;
        }
        if (($node instanceof MethodCall || $node instanceof StaticCall) && $node->isFirstClassCallable()) {
            $data->addDeferredMethodCall($caller, $callee);

            return;
        }
        $data->addMethodCall($caller, $callee);
    }

    /** Records a call from a closure or arrow function in the caller's body. */
    public static function captureDeferredCall(Node $node, LcomClassData $data, string $caller): void
    {
        $callee = self::calleeName($node);
        if ($callee !== null) {
            $data->addDeferredMethodCall($caller, $callee);
        }
    }

    private static function calleeName(Node $node): ?string
    {
        if ($node instanceof MethodCall && self::isThis($node->var) || $node instanceof StaticCall && self::isOwnClass($node->class)) {
            return $node->name instanceof Identifier ? $node->name->toString() : LcomMethodPopulation::ANY_METHOD;
        }

        return self::isOwnCallable($node) ? LcomMethodPopulation::ANY_METHOD : null;
    }

    /**
     * Own-method references whose target the facts cannot name: `[$this, ...]`,
     * `[self::class, ...]`, `[static::class, ...]` and `call_user_func`/`call_user_func_array`
     * applied to `$this`.
     */
    private static function isOwnCallable(Node $node): bool
    {
        return $node instanceof Array_ && self::isOwnCallableArray($node)
            || $node instanceof FuncCall && self::callsUserFunctionOnThis($node);
    }

    private static function isOwnCallableArray(Array_ $node): bool
    {
        $first = $node->items[0] ?? null;

        return \count($node->items) === 2 && $first !== null && (self::isThis($first->value) || self::isOwnClassName($first->value));
    }

    private static function callsUserFunctionOnThis(FuncCall $node): bool
    {
        $first = $node->getRawArgs()[0] ?? null;

        return $node->name instanceof Name
            && \in_array(strtolower($node->name->getLast()), ['call_user_func', 'call_user_func_array'], true)
            && $first instanceof Node\Arg
            && self::isThis($first->value);
    }

    private static function isThis(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'this';
    }

    private static function isOwnClass(Node $node): bool
    {
        return $node instanceof Name && \in_array($node->toLowerString(), ['self', 'static'], true);
    }

    private static function isOwnClassName(Node $node): bool
    {
        return $node instanceof ClassConstFetch
            && self::isOwnClass($node->class)
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'class';
    }
}
