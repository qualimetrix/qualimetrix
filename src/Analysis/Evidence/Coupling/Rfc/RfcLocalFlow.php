<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Rfc;

use PhpParser\Node;

/**
 * Invalidates local receiver facts across uncertain RFC body control flow.
 *
 * @qmx-threshold coupling.cbo warning=24 -- The closed PHP control-flow grammar must invalidate receiver evidence across every supported region; splitting the node vocabulary transfers the same dependencies.
 */
final class RfcLocalFlow
{
    public static function uncertain(Node $node): bool
    {
        return $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_ || $node instanceof Node\Stmt\Switch_ || $node instanceof Node\Stmt\TryCatch || self::conditionalRegion($node);
    }

    public static function alternatives(Node $node): bool
    {
        return $node instanceof Node\Stmt\Switch_ || $node instanceof Node\Stmt\TryCatch || self::conditionalRegion($node);
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
        foreach (self::paths($node) as $path) {
            yield from $path;
        }
    }

    /** @return iterable<list<Node>> */
    public static function paths(Node $node): iterable
    {
        $fields = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $field) {
            $value = $fields[$field];
            if ($value instanceof Node) {
                yield [$value];
            } elseif (\is_array($value)) {
                $nodes = array_values(array_filter($value, static fn($child): bool => $child instanceof Node));
                if ($field === 'stmts') {
                    yield $nodes;
                    continue;
                }
                foreach ($nodes as $child) {
                    yield [$child];
                }
            }
        }
    }

    /** @param array<string, string> $variables */
    private static function forgetTargets(Node $node, array &$variables): void
    {
        if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp) {
            RfcReceiverTypes::forgetTarget($node->var, $variables);
        }
        if ($node instanceof Node\Stmt\Foreach_) {
            RfcReceiverTypes::forgetTarget($node->keyVar, $variables);
            RfcReceiverTypes::forgetTarget($node->valueVar, $variables);
        }
    }

    private static function conditionalRegion(Node $node): bool
    {
        return $node instanceof Node\Expr\Ternary || $node instanceof Node\Expr\Match_ || $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalAnd || $node instanceof Node\Expr\BinaryOp\LogicalOr || $node instanceof Node\Expr\BinaryOp\Coalesce;
    }
}
