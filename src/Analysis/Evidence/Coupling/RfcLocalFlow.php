<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use PhpParser\Node;

/**
 * Invalidates local receiver facts across uncertain RFC body control flow.
 *
 * @qmx-threshold coupling.cbo warning=23 -- The closed PHP control-flow grammar must invalidate receiver evidence across every supported region; splitting the node vocabulary transfers the same dependencies.
 */
final class RfcLocalFlow
{
    public static function uncertain(Node $node): bool
    {
        return $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_ || $node instanceof Node\Stmt\Switch_ || $node instanceof Node\Stmt\TryCatch || self::conditionalRegion($node);
    }

    /** @param array<string, string> $variables */
    public static function forgetWrittenVariables(Node $node, array &$variables): void
    {
        if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            return;
        }
        self::forgetTargets($node, $variables);
        foreach (self::children($node) as $child) {
            self::forgetWrittenVariables($child, $variables);
        }
    }

    /** @return iterable<Node> */
    public static function children(Node $node): iterable
    {
        $fields = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $field) {
            $value = $fields[$field];
            if ($value instanceof Node) {
                yield $value;
            } elseif (\is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        yield $child;
                    }
                }
            }
        }
    }

    /** @param array<string, string> $variables */
    private static function forgetTargets(Node $node, array &$variables): void
    {
        if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp) {
            self::forgetVariable($node->var, $variables);
        }
        if ($node instanceof Node\Stmt\Foreach_) {
            self::forgetVariable($node->keyVar, $variables);
            self::forgetVariable($node->valueVar, $variables);
        }
    }

    /** @param array<string, string> $variables */
    private static function forgetVariable(?Node $variable, array &$variables): void
    {
        if ($variable instanceof Node\Expr\Variable && \is_string($variable->name)) {
            unset($variables[$variable->name]);
        }
    }

    private static function conditionalRegion(Node $node): bool
    {
        return $node instanceof Node\Expr\Ternary || $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalAnd || $node instanceof Node\Expr\BinaryOp\LogicalOr || $node instanceof Node\Expr\BinaryOp\Coalesce;
    }
}
