<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\CodeSmell;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\AbstractCollector;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects identical sub-expression metrics for files.
 *
 * Detects patterns like identical operands ($a === $a), duplicate conditions
 * in if/elseif chains, identical ternary branches, and duplicate match arm conditions.
 *
 * Metrics per finding type:
 * - identicalSubExpression.{type}.count - number of findings of this type
 * - identicalSubExpression.{type}.line.{i} - line number of each finding
 */
final class IdenticalSubExpressionCollector extends AbstractCollector
{
    private const NAME = 'identical-subexpression';

    public const FINDING_TYPES = [
        'identical_operands',
        'duplicate_condition',
        'identical_ternary',
        'duplicate_match_arm',
        'duplicate_switch_case',
    ];

    public function __construct()
    {
        $this->visitor = new IdenticalSubExpressionVisitor();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        $metrics = [];

        foreach (self::FINDING_TYPES as $type) {
            $metrics[] = "identicalSubExpression.{$type}.count";
        }

        return $metrics;
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof IdenticalSubExpressionVisitor);

        $findings = $this->visitor->getFindings();
        $bag = new MetricBag();

        // Group findings by type
        $grouped = [];

        foreach ($findings as $finding) {
            $grouped[$finding->type][] = $finding;
        }

        foreach (self::FINDING_TYPES as $type) {
            $typedFindings = $grouped[$type] ?? [];
            $bag = $bag->with("identicalSubExpression.{$type}.count", \count($typedFindings));

            foreach ($typedFindings as $i => $finding) {
                $bag = $bag->with("identicalSubExpression.{$type}.line.{$i}", $finding->line);
            }
        }

        return $bag;
    }
}
