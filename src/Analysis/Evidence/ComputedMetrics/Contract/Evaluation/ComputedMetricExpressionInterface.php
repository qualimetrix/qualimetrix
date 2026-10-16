<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\ParsedExpression;

/** Formula operations used by Health to preserve authored weights and reached inputs. */
interface ComputedMetricExpressionInterface
{
    public function parse(string $formula): ParsedExpression;

    /** @param array<string, mixed> $variables */
    public function evaluate(string $formula, array $variables): mixed;

    public static function keyReadFrom(?Node $node): ?string;
}
