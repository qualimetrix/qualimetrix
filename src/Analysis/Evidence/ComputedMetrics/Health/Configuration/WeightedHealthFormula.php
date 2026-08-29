<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\NullCoalesceNode;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * The one formula shape `--exclude-health` knows how to rebuild.
 *
 * `clamp((m["health.a"] ?? f) * w + …, 0, 100)`. This is a fact about the health
 * score, not about the formula language, which is why it reads the tree the
 * language hands it rather than living beside the language: the language accepts
 * any expression, and only this shape can have a dimension taken out of it and
 * the rest renormalised.
 *
 * Read off the tree rather than matched in text. The pattern that stood here was
 * narrower than the language the product accepts — it missed a space in
 * `m ["health.a"]` and a fractional fallback — and it reported a PARTIAL read as
 * a successful one, so a term it could not see was silently dropped and the
 * remaining weights renormalised around it. Every term is read, or the answer is
 * null; there is no half-read.
 */
final class WeightedHealthFormula
{
    /**
     * @return array<string, array{weight: float, fallback: float}>|null
     */
    public static function termsOf(ComputedMetricExpression $expression, string $formula): ?array
    {
        try {
            $node = $expression->parse($formula)->getNodes();
        } catch (SyntaxError) {
            return null;
        }

        $sum = self::clampedSum($node);

        if ($sum === null) {
            return null;
        }

        $terms = [];

        foreach (self::addends($sum) as $addend) {
            $term = self::weightedTerm($addend);

            if ($term === null) {
                return null;
            }

            [$key, $weight, $fallback] = $term;
            $terms[$key] = ['weight' => $weight, 'fallback' => $fallback];
        }

        return $terms === [] ? null : $terms;
    }

    /** The sum inside `clamp(<sum>, …)`, or the node itself when it is one. */
    private static function clampedSum(Node $node): ?Node
    {
        if (!$node instanceof FunctionNode || $node->attributes['name'] !== 'clamp') {
            return $node;
        }

        $arguments = $node->nodes['arguments'] ?? null;

        return $arguments instanceof Node ? (array_values($arguments->nodes)[0] ?? null) : null;
    }

    /**
     * @return list<Node>
     */
    private static function addends(Node $node): array
    {
        if ($node instanceof BinaryNode && $node->attributes['operator'] === '+') {
            return [
                ...self::addends($node->nodes['left']),
                ...self::addends($node->nodes['right']),
            ];
        }

        return [$node];
    }

    /**
     * `(m["health.x"] ?? <fallback>) * <weight>`, in either factor order.
     *
     * @return array{0: string, 1: float, 2: float}|null
     */
    private static function weightedTerm(Node $node): ?array
    {
        if (!$node instanceof BinaryNode || $node->attributes['operator'] !== '*') {
            return null;
        }

        return self::guardedTimesConstant($node->nodes['left'], $node->nodes['right'])
            ?? self::guardedTimesConstant($node->nodes['right'], $node->nodes['left']);
    }

    /**
     * One orientation of that product, read or refused.
     *
     * @return array{0: string, 1: float, 2: float}|null
     */
    private static function guardedTimesConstant(Node $guard, Node $weight): ?array
    {
        if (!$guard instanceof NullCoalesceNode || !$weight instanceof ConstantNode) {
            return null;
        }

        $key = ComputedMetricExpression::keyReadFrom($guard->nodes['expr1'] ?? null);
        $fallback = $guard->nodes['expr2'] ?? null;

        if ($key === null || !$fallback instanceof ConstantNode) {
            return null;
        }

        if (!is_numeric($fallback->attributes['value']) || !is_numeric($weight->attributes['value'])) {
            return null;
        }

        return [$key, (float) $weight->attributes['value'], (float) $fallback->attributes['value']];
    }

    private function __construct() {}
}
