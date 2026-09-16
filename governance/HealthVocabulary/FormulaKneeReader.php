<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\HealthVocabulary;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\Node;

/**
 * The thresholds a health formula actually applies, read off its parsed tree.
 *
 * A health formula penalises a term only past a knee: `max(EXPR - 20, 0)` is
 * silent until EXPR passes 20, and `max(85 - EXPR, 0)` is silent while EXPR
 * stays above 85. That knee is the number a report may advertise as the target
 * for the inputs of EXPR; every other number is a claim about the formula that
 * the formula does not make.
 *
 * Read from the tree rather than matched in the text for the reason
 * {@see ComputedMetricExpression} gives: the formulas contain `max(...)` used
 * as a division guard and `/ max(...)` denominators, and a pattern over text
 * cannot tell those from a penalty without becoming a parser.
 *
 * A term with no knee — a plain product, a square root — yields nothing, and
 * that absence is itself asserted: a catalog entry declared knee-less turns red
 * the day a formula gives it one.
 */
final class FormulaKneeReader
{
    public function __construct(
        private readonly ComputedMetricExpression $expression = new ComputedMetricExpression(),
    ) {}

    /**
     * Every knee the formula applies, as (keys it constrains) => knee.
     *
     * @return list<array{direction: 'lower'|'higher', knee: float, keys: list<string>}>
     */
    public function kneesOf(string $formula): array
    {
        $knees = [];
        self::walk($this->expression->parse($formula)->getNodes(), $knees);

        return $knees;
    }

    /**
     * The knee constraining exactly `$keys`, or null when the term has none.
     *
     * @param list<string> $keys
     *
     * @return array{direction: 'lower'|'higher', knee: float}|null
     */
    public function kneeFor(string $formula, array $keys): ?array
    {
        sort($keys);

        foreach ($this->kneesOf($formula) as $knee) {
            $termKeys = $knee['keys'];
            sort($termKeys);

            if ($termKeys === $keys) {
                return ['direction' => $knee['direction'], 'knee' => $knee['knee']];
            }
        }

        return null;
    }

    /**
     * @param list<array{direction: 'lower'|'higher', knee: float, keys: list<string>}> $knees
     */
    private static function walk(Node $node, array &$knees): void
    {
        $knee = self::kneeAt($node);

        if ($knee !== null) {
            $knees[] = $knee;
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                self::walk($child, $knees);
            }
        }
    }

    /**
     * The knee this node is, if it is one.
     *
     * Two shapes carry a knee in the built-in formulas: `max(D, 0)`, which
     * floors a penalty at nothing, and `clamp(D / span, 0, 1)`, which scales one
     * between a knee and a saturation point. Both take the same difference D.
     *
     * @return array{direction: 'lower'|'higher', knee: float, keys: list<string>}|null
     */
    private static function kneeAt(Node $node): ?array
    {
        if (!$node instanceof FunctionNode) {
            return null;
        }

        $arguments = $node->nodes['arguments'] ?? null;
        $argumentList = $arguments instanceof Node ? array_values($arguments->nodes) : [];
        $name = $node->attributes['name'] ?? '';

        if ($name === 'max' && \count($argumentList) === 2) {
            foreach ([[0, 1], [1, 0]] as [$difference, $floor]) {
                if (self::constantOf($argumentList[$floor]) === 0.0) {
                    return self::kneeOfDifference($argumentList[$difference]);
                }
            }

            return null;
        }

        if ($name === 'clamp' && \count($argumentList) === 3) {
            $scaled = $argumentList[0];

            return $scaled instanceof BinaryNode && ($scaled->attributes['operator'] ?? null) === '/'
                ? self::kneeOfDifference($scaled->nodes['left'] ?? null)
                : null;
        }

        return null;
    }

    /**
     * `EXPR - C` is an upper knee on EXPR's keys; `C - EXPR` a lower one.
     *
     * @return array{direction: 'lower'|'higher', knee: float, keys: list<string>}|null
     */
    private static function kneeOfDifference(?Node $node): ?array
    {
        if (!$node instanceof BinaryNode || ($node->attributes['operator'] ?? null) !== '-') {
            return null;
        }

        $left = $node->nodes['left'] ?? null;
        $right = $node->nodes['right'] ?? null;
        $leftConstant = self::constantOf($left);
        $rightConstant = self::constantOf($right);

        if ($rightConstant !== null && $leftConstant === null && $left instanceof Node) {
            return ['direction' => 'lower', 'knee' => $rightConstant, 'keys' => self::keysIn($left)];
        }

        if ($leftConstant !== null && $rightConstant === null && $right instanceof Node) {
            return ['direction' => 'higher', 'knee' => $leftConstant, 'keys' => self::keysIn($right)];
        }

        return null;
    }

    private static function constantOf(?Node $node): ?float
    {
        if (!$node instanceof ConstantNode) {
            return null;
        }

        $value = $node->attributes['value'] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    /** @return list<string> */
    private static function keysIn(Node $node): array
    {
        $keys = [];
        $key = ComputedMetricExpression::keyReadFrom($node);

        if ($key !== null) {
            $keys[] = $key;
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                $keys = [...$keys, ...self::keysIn($child)];
            }
        }

        return array_values(array_unique($keys));
    }
}
