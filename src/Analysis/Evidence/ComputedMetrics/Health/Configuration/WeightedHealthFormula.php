<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/** The complete ordered health mean that exclusion can rebuild. */
final class WeightedHealthFormula
{
    /** @return array<string, array{weight: float}>|null */
    public static function termsOf(ComputedMetricExpression $expression, string $formula): ?array
    {
        try {
            $node = $expression->parse($formula)->getNodes();
        } catch (SyntaxError) {
            return null;
        }
        $mean = self::clampedMean($node);
        if (!$mean instanceof FunctionNode || $mean->attributes['name'] !== 'weighted_mean') {
            return null;
        }
        $arguments = array_values($mean->nodes['arguments']->nodes);
        $argumentCount = \count($arguments);
        if ($argumentCount === 0 || $argumentCount % 2 !== 0) {
            return null;
        }
        $terms = [];
        for ($index = 0; $index < $argumentCount; $index += 2) {
            $term = self::termOf($arguments[$index], $arguments[$index + 1]);
            if ($term === null || isset($terms[$term['key']])) {
                return null;
            }
            $terms[$term['key']] = ['weight' => $term['weight']];
        }

        return $terms;
    }

    /** @return array{key: string, weight: float}|null */
    private static function termOf(Node $value, Node $weightNode): ?array
    {
        $key = ComputedMetricExpression::keyReadFrom($value);
        $weight = self::weightOf($weightNode);

        return $key !== null && str_starts_with($key, 'health.') && $weight !== null
            ? ['key' => $key, 'weight' => $weight]
            : null;
    }

    private static function clampedMean(Node $node): ?Node
    {
        if (!$node instanceof FunctionNode || $node->attributes['name'] !== 'clamp') {
            return $node;
        }
        $arguments = array_values($node->nodes['arguments']->nodes);
        if (\count($arguments) !== 3 || !self::isConstant($arguments[1], 0) || !self::isConstant($arguments[2], 100)) {
            return null;
        }

        return $arguments[0];
    }

    private static function isConstant(Node $node, int $value): bool
    {
        return $node instanceof ConstantNode && $node->attributes['value'] === $value;
    }

    private static function weightOf(Node $node): ?float
    {
        if (!$node instanceof ConstantNode) {
            return null;
        }
        $weight = $node->attributes['value'];

        return (\is_int($weight) || \is_float($weight)) && is_finite((float) $weight) && $weight > 0 ? (float) $weight : null;
    }

    private function __construct() {}
}
