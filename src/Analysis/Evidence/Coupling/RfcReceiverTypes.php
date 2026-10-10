<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use PhpParser\Node;
use Qualimetrix\Core\Ast\ResolvedName;

/**
 * Recognizes the declared and local receiver evidence supported by RFC.
 *
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
            $type = self::typeName($parameter->type);
            if ($type !== null) {
                $variables[$parameter->var->name] = $type;
            }
        }
    }

    /** @param array<string, string> $variables */
    public static function receiver(Node\Expr $expression, array $variables): ?string
    {
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
    public static function bind(Node\Expr\Assign $node, array &$variables): void
    {
        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            $type = self::receiver($node->expr, $variables);
            unset($variables[$node->var->name]);
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
        if ($node->var instanceof Node\Expr\Variable && \is_string($node->var->name)) {
            unset($variables[$node->var->name]);
        }
    }
}
