<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

use PhpParser\Node;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\AbstractCollector;
use SplFileInfo;

/**
 * Collects identical sub-expression metrics for files.
 *
 * Detects patterns like identical operands ($a === $a), duplicate conditions
 * in if/elseif chains, identical ternary branches, and duplicate match arm conditions.
 *
 * Entries (identicalSubExpression.{type}):
 * - line: int — line number of each finding
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
            $metrics[] = "identicalSubExpression.{$type}";
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

        foreach ($findings as $finding) {
            $bag = $bag->withEntry("identicalSubExpression.{$finding->type}", [
                'line' => $finding->line,
            ]);
        }

        return $bag;
    }
}
