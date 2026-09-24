<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConditionalNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\NullCoalesceNode;

/**
 * The metrics a parsed formula reads, and which absent ones it would read
 * where it cannot use `null` — in arithmetic, a function argument, a
 * condition, or as the formula's own value.
 *
 * Where a read sits decides that, not the key alone:
 * - The right side of `??` counts where its left side is absent. A left side
 *   other than a read or another `??` might be null or not, so its right side
 *   is counted as read.
 * - A ternary's condition always runs, and a `null` there chooses the branch,
 *   so a bare read in it counts like one in arithmetic.
 * - A ternary branch, and the right side of `and`/`or`, run only on a value
 *   the symbol carries. Before a run only a key every path reads counts;
 *   after one, {@see ComputedMetricBranchTrace} says which operands ran.
 */
final class ComputedMetricReads
{
    /** The single variable a formula sees. */
    public const string VARIABLE = 'm';

    /** Operators whose right side runs only on the left side's value. */
    private const array SHORT_CIRCUIT_OPERATORS = ['and', '&&', 'or', '||'];

    /**
     * The key a node reads out of `m`, or null if it is not such a read.
     *
     * The base is checked, not just the index: `m["a"]["b"]` indexes the VALUE
     * the first read returned and reads no key `b`.
     */
    public static function keyOf(?Node $node): ?string
    {
        if (!$node instanceof GetAttrNode || $node->attributes['type'] !== GetAttrNode::ARRAY_CALL) {
            return null;
        }

        $base = $node->nodes['node'] ?? null;

        if (!$base instanceof NameNode || $base->attributes['name'] !== self::VARIABLE) {
            return null;
        }

        $attribute = $node->nodes['attribute'] ?? null;

        return $attribute instanceof ConstantNode && \is_string($attribute->attributes['value'])
            ? $attribute->attributes['value']
            : null;
    }

    /**
     * The absent keys the formula reads where it cannot use `null`: on every
     * path its evaluation can take, or, given the conditional operands a run
     * entered, on the path it took.
     *
     * @param callable(string): bool $isPresent
     * @param ?callable(Node, string): bool $entered whether a run entered an operand of a node; null before a run
     *
     * @return list<string> in order of first appearance
     */
    public static function missingOf(Node $root, callable $isPresent, ?callable $entered = null): array
    {
        [$consumed, $value] = self::absentReads($root, $isPresent, $entered);

        return array_values(array_unique([...$consumed, ...$value]));
    }

    /**
     * Absent reads under a node, split by where their `null` goes.
     *
     * The second list holds the reads whose `null` becomes the node's own
     * value, which an enclosing `??` can still catch; the first holds those
     * already consumed by an operator or a function, which nothing can.
     *
     * @param callable(string): bool $isPresent
     * @param ?callable(Node, string): bool $entered which conditional operands ran; null before a run
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function absentReads(Node $node, callable $isPresent, ?callable $entered): array
    {
        $key = self::keyOf($node);

        return match (true) {
            $key !== null => [[], $isPresent($key) ? [] : [$key]],
            $node instanceof NullCoalesceNode => self::absentReadsOfFallback($node, $isPresent, $entered),
            $node instanceof ConditionalNode => self::absentReadsOfBranches($node, $isPresent, $entered),
            default => [self::absentReadsOfOperands($node, $isPresent, $entered), []],
        };
    }

    /**
     * @param callable(string): bool $isPresent
     * @param ?callable(Node, string): bool $entered
     *
     * @return list<string>
     */
    private static function absentReadsOfOperands(Node $node, callable $isPresent, ?callable $entered): array
    {
        $conditional = self::conditionalOperands($node);
        $consumed = [];

        foreach ($node->nodes as $name => $child) {
            // A conditional operand counts only where a run entered it.
            $skipped = \in_array($name, $conditional, true) && ($entered === null || !$entered($node, $name));

            if ($child instanceof Node && !$skipped) {
                [$childConsumed, $childValue] = self::absentReads($child, $isPresent, $entered);
                $consumed = [...$consumed, ...$childConsumed, ...$childValue];
            }
        }

        return $consumed;
    }

    /**
     * @param callable(string): bool $isPresent
     * @param ?callable(Node, string): bool $entered
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function absentReadsOfFallback(NullCoalesceNode $node, callable $isPresent, ?callable $entered): array
    {
        $left = $node->nodes['expr1'];
        [$consumed, $value] = self::absentReads($left, $isPresent, $entered);

        // Only a read or another `??` says here whether it is null. Any
        // other left side might be — a ternary, `max()` of two nulls — so
        // the right side is taken as read: it cannot be decided, and
        // demanding a key is the side that fabricates nothing.
        if ($value === [] && (self::keyOf($left) !== null || $left instanceof NullCoalesceNode)) {
            return [$consumed, []];
        }

        [$rightConsumed, $rightValue] = self::absentReads($node->nodes['expr2'], $isPresent, $entered);

        // Null only when both sides are; then both sides' keys are why.
        return [[...$consumed, ...$rightConsumed], $rightValue === [] ? [] : [...$value, ...$rightValue]];
    }

    /**
     * @param callable(string): bool $isPresent
     * @param ?callable(Node, string): bool $entered
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function absentReadsOfBranches(ConditionalNode $node, callable $isPresent, ?callable $entered): array
    {
        [$consumed, $conditionValue] = self::absentReads($node->nodes['expr1'], $isPresent, $entered);
        $consumed = [...$consumed, ...$conditionValue];
        $value = [];
        $branches = $entered === null ? [] : array_filter(['expr2', 'expr3'], static fn(string $branch): bool => $entered($node, $branch));

        foreach ($branches as $branch) {
            [$branchConsumed, $branchValue] = self::absentReads($node->nodes[$branch], $isPresent, $entered);
            $consumed = [...$consumed, ...$branchConsumed];
            $value = [...$value, ...$branchValue];
        }

        if ($branches !== []) {
            return [$consumed, $value];
        }

        // Before a run, or where a run stopped before a branch: only what
        // either branch would read is certain. An enclosing operand refused
        // to run on exactly such a read.
        [$certainConsumed, $certainValue] = self::readOnEitherBranch($node, $isPresent);

        return [[...$consumed, ...$certainConsumed], $certainValue];
    }

    /**
     * @param callable(string): bool $isPresent
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function readOnEitherBranch(ConditionalNode $node, callable $isPresent): array
    {
        [$thenConsumed, $thenValue] = self::absentReads($node->nodes['expr2'], $isPresent, null);
        [$elseConsumed, $elseValue] = self::absentReads($node->nodes['expr3'], $isPresent, null);

        // Consumed on both paths fails regardless; read on both paths but
        // handed on by one of them fails unless an enclosing `??` catches it.
        $bothConsumed = array_values(array_intersect($thenConsumed, $elseConsumed));
        $bothRead = array_intersect([...$thenConsumed, ...$thenValue], [...$elseConsumed, ...$elseValue]);

        return [$bothConsumed, array_values(array_diff($bothRead, $bothConsumed))];
    }

    /**
     * The operands of a node that run only on a value the evaluation computes.
     *
     * @return list<string>
     */
    public static function conditionalOperands(Node $node): array
    {
        if ($node instanceof ConditionalNode) {
            return ['expr2', 'expr3'];
        }

        if ($node instanceof BinaryNode && \in_array($node->attributes['operator'], self::SHORT_CIRCUIT_OPERATORS, true)) {
            return ['right'];
        }

        return [];
    }
}
