<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Size;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects Lines of Code metrics.
 *
 * Metrics:
 * - loc:{path} — Total lines of code
 * - lloc:{path} — Logical lines (non-empty, non-comment)
 * - cloc:{path} — Comment lines
 *
 * LLOC = LOC - empty lines - CLOC
 */
final class LocCollector extends AbstractCollector
{
    private const NAME = 'loc';
    private const METRIC_LOC = 'loc';
    private const METRIC_LLOC = 'lloc';
    private const METRIC_CLOC = 'cloc';

    public function __construct()
    {
        $this->visitor = new LocVisitor();
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
        return [
            self::METRIC_LOC,
            self::METRIC_LLOC,
            self::METRIC_CLOC,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        if (!$file->isFile() || !$file->isReadable()) {
            return (new MetricBag())
                ->with(self::METRIC_LOC, 0)
                ->with(self::METRIC_LLOC, 0)
                ->with(self::METRIC_CLOC, 0);
        }

        $content = file_get_contents($file->getPathname());

        if ($content === false) {
            return (new MetricBag())
                ->with(self::METRIC_LOC, 0)
                ->with(self::METRIC_LLOC, 0)
                ->with(self::METRIC_CLOC, 0);
        }

        $metrics = $this->calculateMetrics($content);

        return (new MetricBag())
            ->with(self::METRIC_LOC, $metrics['loc'])
            ->with(self::METRIC_LLOC, $metrics['lloc'])
            ->with(self::METRIC_CLOC, $metrics['cloc']);
    }

    /**
     * @return array{loc: int, lloc: int, cloc: int}
     */
    private function calculateMetrics(string $content): array
    {
        // Handle empty content
        if ($content === '') {
            return ['loc' => 0, 'lloc' => 0, 'cloc' => 0];
        }

        $lines = explode("\n", $content);
        $loc = \count($lines);

        // Track which lines contain comments
        /** @var array<int, bool> */
        $commentLines = [];

        // Track which lines are empty
        /** @var array<int, bool> */
        $emptyLines = [];

        // Identify empty lines
        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') {
                $emptyLines[$lineNumber + 1] = true;
            }
        }

        // Use PHP tokenizer to find comment lines
        $tokens = @token_get_all($content);

        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                continue;
            }

            [$tokenId, $tokenContent, $tokenLine] = $token;

            if ($tokenId === \T_COMMENT || $tokenId === \T_DOC_COMMENT) {
                // Mark all lines covered by this comment
                $commentLineCount = substr_count($tokenContent, "\n");

                for ($i = 0; $i <= $commentLineCount; $i++) {
                    $commentLines[$tokenLine + $i] = true;
                }
            }
        }

        $cloc = \count($commentLines);
        $emptyCount = \count($emptyLines);
        $lloc = $loc - $emptyCount - $cloc;

        // LLOC can't be negative (in case of overlap between empty and comment lines)
        if ($lloc < 0) {
            // Recalculate: some lines might be both empty and comments (shouldn't happen, but be safe)
            $pureEmptyCount = 0;

            foreach ($emptyLines as $line => $isEmpty) {
                if (!isset($commentLines[$line])) {
                    ++$pureEmptyCount;
                }
            }
            $lloc = $loc - $pureEmptyCount - $cloc;
        }

        return [
            'loc' => $loc,
            'lloc' => max(0, $lloc),
            'cloc' => $cloc,
        ];
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        $aggregations = [
            SymbolLevel::Namespace_->value => [
                AggregationStrategy::Sum,
                AggregationStrategy::Average,
            ],
            SymbolLevel::Project->value => [
                AggregationStrategy::Sum,
                AggregationStrategy::Average,
            ],
        ];

        return [
            new MetricDefinition(
                name: self::METRIC_LOC,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_LLOC,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_CLOC,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
        ];
    }
}
