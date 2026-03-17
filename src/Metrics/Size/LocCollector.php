<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Size;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\ClassMetricsProviderInterface;
use AiMessDetector\Core\Metric\ClassWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricName;
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
 * - lloc:{path} — Logical lines (lines with at least one code token)
 * - cloc:{path} — Pure comment lines (no code tokens on the same line)
 * - classLoc — Physical LOC per class (endLine - startLine + 1)
 *
 * A line with both code and an inline comment (e.g., `$a = 1; // note`)
 * counts as LLOC but NOT as CLOC. Only lines where ALL tokens are
 * comments/whitespace count as CLOC.
 */
final class LocCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'loc';

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
            MetricName::SIZE_LOC,
            MetricName::SIZE_LLOC,
            MetricName::SIZE_CLOC,
            MetricName::SIZE_CLASS_LOC,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        if (!$file->isFile() || !$file->isReadable()) {
            return (new MetricBag())
                ->with(MetricName::SIZE_LOC, 0)
                ->with(MetricName::SIZE_LLOC, 0)
                ->with(MetricName::SIZE_CLOC, 0);
        }

        $content = file_get_contents($file->getPathname());

        if ($content === false) {
            return (new MetricBag())
                ->with(MetricName::SIZE_LOC, 0)
                ->with(MetricName::SIZE_LLOC, 0)
                ->with(MetricName::SIZE_CLOC, 0);
        }

        $metrics = $this->calculateMetrics($content);

        $bag = (new MetricBag())
            ->with(MetricName::SIZE_LOC, $metrics['loc'])
            ->with(MetricName::SIZE_LLOC, $metrics['lloc'])
            ->with(MetricName::SIZE_CLOC, $metrics['cloc']);

        // Store class-level LOC with class FQN as key
        \assert($this->visitor instanceof LocVisitor);

        foreach ($this->visitor->getClassRanges() as $classFqn => $range) {
            $classLoc = $range['endLine'] - $range['startLine'] + 1;
            $bag = $bag->with(MetricName::SIZE_CLASS_LOC . ':' . $classFqn, $classLoc);
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        \assert($this->visitor instanceof LocVisitor);

        $result = [];

        foreach ($this->visitor->getClassRanges() as $range) {
            $classLoc = $range['endLine'] - $range['startLine'] + 1;

            $bag = (new MetricBag())
                ->with(MetricName::SIZE_CLASS_LOC, $classLoc);

            $result[] = new ClassWithMetrics(
                namespace: $range['namespace'],
                class: $range['className'],
                line: $range['startLine'],
                metrics: $bag,
            );
        }

        return $result;
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

        // Track which lines contain comment tokens
        /** @var array<int, true> */
        $commentLines = [];

        // Track which lines have non-comment code tokens (array tokens only)
        /** @var array<int, true> */
        $codeLines = [];

        // Track which lines are empty
        /** @var array<int, true> */
        $emptyLines = [];

        // Identify empty lines
        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') {
                $emptyLines[$lineNumber + 1] = true;
            }
        }

        // Use PHP tokenizer to classify lines
        $tokens = @token_get_all($content);

        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                // Single-character tokens ('{', '}', ';', etc.) don't carry line
                // information in token_get_all. Lines containing only such tokens
                // are handled below by checking non-empty, non-comment lines.
                continue;
            }

            [$tokenId, $tokenContent, $tokenLine] = $token;

            if ($tokenId === \T_COMMENT || $tokenId === \T_DOC_COMMENT) {
                // Mark all lines covered by this comment
                $commentLineCount = substr_count($tokenContent, "\n");

                for ($i = 0; $i <= $commentLineCount; $i++) {
                    $commentLines[$tokenLine + $i] = true;
                }
            } elseif ($tokenId !== \T_WHITESPACE
                && $tokenId !== \T_OPEN_TAG
                && $tokenId !== \T_CLOSE_TAG
            ) {
                // Non-whitespace, non-comment, non-tag array token = code
                $tokenLineCount = substr_count($tokenContent, "\n");

                for ($i = 0; $i <= $tokenLineCount; $i++) {
                    $codeLines[$tokenLine + $i] = true;
                }
            }
        }

        // A "pure comment line" has comment tokens but NO code tokens.
        // Lines with both code and comments (inline comments) are code lines, not CLOC.
        // Lines that are non-empty and not marked as either code or comment must have
        // single-character code tokens (braces, semicolons, etc.) — they are code lines.
        $pureCommentLineCount = 0;

        foreach ($commentLines as $line => $_) {
            if (!isset($codeLines[$line])) {
                ++$pureCommentLineCount;
            }
        }

        // LLOC = LOC - empty lines - pure comment lines
        $emptyCount = \count($emptyLines);
        $lloc = $loc - $emptyCount - $pureCommentLineCount;

        return [
            'loc' => $loc,
            'lloc' => max(0, $lloc),
            'cloc' => $pureCommentLineCount,
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
                name: MetricName::SIZE_LOC,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_LLOC,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_CLOC,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_CLASS_LOC,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
        ];
    }
}
