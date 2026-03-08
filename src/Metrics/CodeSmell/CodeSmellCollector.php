<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\CodeSmell;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\AbstractCollector;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects code smell metrics for files.
 *
 * Detects various code smells and stores counts for each type.
 *
 * Metrics:
 * - codeSmell.{type}.count - number of occurrences
 *
 * Types: goto, eval, exit, empty_catch, debug_code, error_suppression, count_in_loop, superglobals, boolean_argument
 */
final class CodeSmellCollector extends AbstractCollector
{
    private const NAME = 'code-smell';

    public const SMELL_TYPES = [
        'goto',
        'eval',
        'exit',
        'empty_catch',
        'debug_code',
        'error_suppression',
        'count_in_loop',
        'superglobals',
        'boolean_argument',
    ];

    public function __construct()
    {
        $this->visitor = new CodeSmellVisitor();
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

        foreach (self::SMELL_TYPES as $type) {
            $metrics[] = "codeSmell.{$type}.count";
            // Line data keys (codeSmell.{type}.line.{i}) are dynamic
        }

        return $metrics;
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof CodeSmellVisitor);

        $bag = new MetricBag();

        foreach (self::SMELL_TYPES as $type) {
            $locations = $this->visitor->getLocationsByType($type);
            $count = \count($locations);

            $bag = $bag->with("codeSmell.{$type}.count", $count);

            foreach ($locations as $i => $location) {
                $bag = $bag->with("codeSmell.{$type}.line.{$i}", $location->line);
            }
        }

        return $bag;
    }
}
