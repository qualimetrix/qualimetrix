<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Rfc;

use PhpParser\Node;
use Qualimetrix\Core\Ast\ResolvedName;

/**
 * Recognizes the declared and local receiver evidence supported by RFC.
 *
 * @qmx-threshold coupling.cbo warning=21 -- Receiver evidence recognizes the supported PHP type and write-target grammar; splitting its node vocabulary transfers the same dependencies.
 * @qmx-threshold coupling.instability warning=0.875001 -- Receiver evidence reads the supported PHP type and expression grammar; its small incoming population does not model that required outward composition.
 */
final class RfcReceiverTypes
{
    public static function typeName(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return self::typeName($type->type);
        }
        if ($type instanceof Node\UnionType) {
            $types = array_values(array_filter($type->types, static fn(Node $part): bool => !($part instanceof Node\Identifier && strtolower($part->toString()) === 'null')));

            return \count($types) === 1 ? self::typeName($types[0]) : null;
        }
        if (!$type instanceof Node\Name) {
            return null;
        }
        $written = strtolower($type->toString());

        return \in_array($written, ['self', 'static', 'parent'], true) ? $written : ResolvedName::className($type);
    }

    /** @param array<Node\Param> $parameters
     * @param array<string, string> $variables
     */
    public static function parameters(array $parameters, array &$variables): void
    {
        foreach ($parameters as $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !\is_string($parameter->var->name)) {
                continue;
            }
            unset($variables[$parameter->var->name]);
            $type = $parameter->variadic ? null : self::typeName($parameter->type);
            if ($type !== null) {
                $variables[$parameter->var->name] = $type;
            }
        }
    }

    /** @param array<string, string> $variables */
    public static function receiver(Node\Name|Node\Expr $expression, array $variables): ?string
    {
        if ($expression instanceof Node\Name) {
            return self::typeName($expression);
        }
        if ($expression instanceof Node\Expr\Variable && \is_string($expression->name)) {
            return $expression->name === 'this' ? 'self' : ($variables[$expression->name] ?? null);
        }
        if ($expression instanceof Node\Expr\New_) {
            return self::typeName($expression->class);
        }
        if ($expression instanceof Node\Expr\PropertyFetch || $expression instanceof Node\Expr\NullsafePropertyFetch) {
            return self::property($expression);
        }

        return null;
    }

    private static function property(Node\Expr\PropertyFetch|Node\Expr\NullsafePropertyFetch $expression): ?string
    {
        if ($expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this' && $expression->name instanceof Node\Identifier) {
            return 'property:' . $expression->name->toString();
        }

        return null;
    }

    /** @param array<string, string> $variables */
    public static function positiveInstanceof(Node\Expr $condition, array &$variables): void
    {
        if (!$condition instanceof Node\Expr\Instanceof_ || !$condition->expr instanceof Node\Expr\Variable || !\is_string($condition->expr->name)) {
            return;
        }
        $type = self::typeName($condition->class);
        if ($type !== null) {
            $variables[$condition->expr->name] = $type;
        }
    }

    /** @param array<string, string> $variables */
    public static function bind(Node\Expr\Assign $node, array &$variables): void
    {
        $type = self::receiver($node->expr, $variables);
        self::forgetTarget($node->var, $variables);
        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            if ($type !== null) {
                $variables[$node->var->name] = $type;
            }
        }
    }

    /** @param array<string, string> $variables */
    public static function invalidate(Node $node, array &$variables): void
    {
        if (!$node instanceof Node\Expr\AssignOp && !$node instanceof Node\Expr\AssignRef) {
            return;
        }
        self::forgetTarget($node->var, $variables);
    }

    /** @param array<string, string> $variables */
    public static function forgetTarget(?Node $target, array &$variables): void
    {
        if ($target instanceof Node\Expr\Variable) {
            if (\is_string($target->name)) {
                unset($variables[$target->name]);
            } else {
                $variables = [];
            }
        } elseif ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    self::forgetTarget($item->value, $variables);
                }
            }
        }
    }
}
