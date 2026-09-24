<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use Override;
use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareTrait;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceMetricProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceWithMetrics;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Collects Lines of Code metrics.
 *
 * Metrics:
 * - loc:{path} — Total lines of code, counted like `wc -l` plus an
 *   unterminated last line: a file's final line break does not open a line
 * - lloc:{path} — Logical lines (lines with at least one code token)
 * - cloc:{path} — Pure comment lines (no code tokens on the same line)
 * - classLoc — Physical LOC per class (endLine - startLine + 1)
 *
 * A line with both code and an inline comment (e.g., `$a = 1; // note`)
 * counts as LLOC but NOT as CLOC. Only lines where ALL tokens are
 * comments/whitespace count as CLOC.
 */
final class LocCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface, NamespaceMetricProviderInterface
{
    use DeclarationIndexAwareTrait;

    private const NAME = 'loc';

    /** @var list<NamespaceWithMetrics> */
    private array $namespaceMetrics = [];

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
        $this->namespaceMetrics = $this->calculateNamespaceMetrics($content);

        $bag = (new MetricBag())
            ->with(MetricName::SIZE_LOC, $metrics[MetricName::SIZE_LOC])
            ->with(MetricName::SIZE_LLOC, $metrics[MetricName::SIZE_LLOC])
            ->with(MetricName::SIZE_CLOC, $metrics[MetricName::SIZE_CLOC]);

        // Store class-level LOC with class FQN as key
        \assert($this->visitor instanceof LocVisitor);

        foreach ($this->visitor->getClassRanges() as $classFqn => $range) {
            $classLoc = $range['endLine'] - $range['startLine'] + 1;
            $bag = $bag->with(MetricName::SIZE_CLASS_LOC . ':' . $classFqn, $classLoc);
        }

        return $bag;
    }

    /**
     * @return list<NamespaceWithMetrics>
     */
    public function getNamespacesWithMetrics(): array
    {
        return $this->namespaceMetrics;
    }

    public function reset(): void
    {
        parent::reset();
        $this->namespaceMetrics = [];
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof LocVisitor);

        $result = [];

        foreach ($this->visitor->getClassRanges() as $range) {
            $classLoc = $range['endLine'] - $range['startLine'] + 1;

            $bag = (new MetricBag())
                ->with(MetricName::SIZE_CLASS_LOC, $classLoc);

            $result[] = $this->classWithMetrics(SymbolPath::forClass($range['namespace'] ?? '', $range['className']), $file, $range['startFilePos'], $range['startLine'], $bag);
        }

        return $result;
    }

    /**
     * The keys are metric names: this array becomes a `MetricBag` unchanged,
     * so a literal here would be the published vocabulary written twice.
     *
     * @return array<string, int>
     */
    private function calculateMetrics(string $content, int $startLine = 1, ?int $endLine = null): array
    {
        // Handle empty content
        if ($content === '') {
            return [MetricName::SIZE_LOC => 0, MetricName::SIZE_LLOC => 0, MetricName::SIZE_CLOC => 0];
        }

        $lines = $this->physicalLines($content);
        $endLine ??= \count($lines);
        $startLine = max(1, $startLine);
        $endLine = min(\count($lines), $endLine);
        $loc = max(0, $endLine - $startLine + 1);

        // Identify empty lines
        /** @var array<int, true> */
        $emptyLines = [];
        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') {
                $emptyLines[$lineNumber + 1] = true;
            }
        }

        [$commentLines, $codeLines] = $this->tokenLines($content);

        // A "pure comment line" has comment tokens but NO code tokens.
        // Lines with both code and comments (inline comments) are code lines, not CLOC.
        // Lines that are non-empty and not marked as either code or comment must have
        // single-character code tokens (braces, semicolons, etc.) — they are code lines.
        // LLOC = LOC - empty lines - pure comment lines
        $emptyCount = $this->countLinesInRange($emptyLines, $startLine, $endLine);
        $pureCommentLineCount = 0;
        foreach ($commentLines as $line => $_) {
            if ($line >= $startLine && $line <= $endLine && !isset($codeLines[$line])) {
                ++$pureCommentLineCount;
            }
        }
        $lloc = $loc - $emptyCount - $pureCommentLineCount;

        return [
            MetricName::SIZE_LOC => $loc,
            MetricName::SIZE_LLOC => max(0, $lloc),
            MetricName::SIZE_CLOC => $pureCommentLineCount,
        ];
    }

    /**
     * The lines that carry a comment token and the lines that carry a code
     * token, as the PHP tokenizer sees them. A line can be in both.
     *
     * @return array{array<int, true>, array<int, true>}
     */
    private function tokenLines(string $content): array
    {
        /** @var array<int, true> */
        $commentLines = [];
        /** @var array<int, true> */
        $codeLines = [];

        foreach (@token_get_all($content) as $token) {
            if (!\is_array($token)) {
                // Single-character tokens ('{', '}', ';', etc.) don't carry line
                // information in token_get_all. Lines containing only such tokens
                // are handled by the caller as non-empty, non-comment lines.
                continue;
            }

            [$tokenId, $tokenContent, $tokenLine] = $token;

            if ($tokenId === \T_COMMENT || $tokenId === \T_DOC_COMMENT) {
                $this->markLines($commentLines, $tokenLine, $tokenContent);
            } elseif ($tokenId !== \T_WHITESPACE
                && $tokenId !== \T_OPEN_TAG
                && $tokenId !== \T_CLOSE_TAG
            ) {
                $this->markLines($codeLines, $tokenLine, $tokenContent);
            }
        }

        return [$commentLines, $codeLines];
    }

    /**
     * @param array<int, true> $lines
     */
    private function markLines(array &$lines, int $firstLine, string $tokenContent): void
    {
        $lineCount = substr_count($tokenContent, "\n");

        for ($i = 0; $i <= $lineCount; $i++) {
            $lines[$firstLine + $i] = true;
        }
    }

    /**
     * A final line break terminates the last line; it does not open another.
     *
     * @return list<string>
     */
    private function physicalLines(string $content): array
    {
        return explode("\n", str_ends_with($content, "\n") ? substr($content, 0, -1) : $content);
    }

    /**
     * @return list<NamespaceWithMetrics>
     */
    private function calculateNamespaceMetrics(string $content): array
    {
        \assert($this->visitor instanceof LocVisitor);
        $rangesByNamespace = $this->visitor->getNamespaceRanges();

        if ($rangesByNamespace === []) {
            $metrics = $this->calculateMetrics($content);

            return [new NamespaceWithMetrics(
                namespace: '',
                line: 1,
                metrics: MetricBag::fromArray($metrics),
            )];
        }

        $result = [];
        foreach ($rangesByNamespace as $namespace => $ranges) {
            $totals = [MetricName::SIZE_LOC => 0, MetricName::SIZE_LLOC => 0, MetricName::SIZE_CLOC => 0];
            foreach ($ranges as $range) {
                $metrics = $this->calculateMetrics($content, $range['startLine'], $range['endLine']);
                foreach ($totals as $name => $value) {
                    $totals[$name] = $value + $metrics[$name];
                }
            }

            $result[] = new NamespaceWithMetrics(
                namespace: $namespace,
                line: $ranges[0]['startLine'],
                metrics: MetricBag::fromArray($totals),
            );
        }

        return $result;
    }

    /**
     * @param array<int, true> $lines
     */
    private function countLinesInRange(array $lines, int $startLine, int $endLine): int
    {
        $count = 0;
        foreach ($lines as $line => $_) {
            if ($line >= $startLine && $line <= $endLine) {
                ++$count;
            }
        }

        return $count;
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
