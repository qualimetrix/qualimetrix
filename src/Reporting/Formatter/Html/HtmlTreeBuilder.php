<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Composer\InstalledVersions;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Version;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

/**
 * Builds the complete data structure for the HTML report.
 *
 * Constructs a hierarchical tree of namespaces and classes with metrics,
 * findings, and debt information attached to each node.
 *
 * Delegates to focused helpers:
 * - {@see HtmlFindingPartitioner} — finding partitioning and attachment
 * - {@see HtmlMetricAggregator} — bottom-up metric aggregation
 * - {@see HtmlDebtCalculator} — debt computation and aggregation
 */
final class HtmlTreeBuilder
{
    private const string NO_NAMESPACE_LABEL = '(no namespace)';

    private readonly HtmlFindingPartitioner $findingPartitioner;
    private readonly HtmlMetricAggregator $metricAggregator;
    private readonly HtmlDebtCalculator $htmlDebtCalculator;

    public function __construct(
        private readonly DebtCalculator $debtCalculator,
        private readonly ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {
        $this->findingPartitioner = new HtmlFindingPartitioner();
        $this->metricAggregator = new HtmlMetricAggregator();
        $this->htmlDebtCalculator = new HtmlDebtCalculator($this->debtCalculator);
    }

    /**
     * Builds the complete data structure for the HTML report.
     *
     * @return array<string, mixed> JSON-ready structure with keys: project, tree, summary, computedMetricDefinitions
     */
    public function build(Report $report, FormatterContext $context, bool $scopedReporting = false): array
    {
        // 1. Build the tree from metrics
        $projectNameOpt = $context->getOption('project-name');
        $projectName = $projectNameOpt !== '' ? $projectNameOpt : null;
        $root = $this->buildTree($report, $projectName);

        // 2. Attach findings to tree nodes
        $nodesByPath = $this->indexNodes($root);
        $findingsByNode = $this->findingPartitioner->partition($report->findings, $nodesByPath);
        $this->findingPartitioner->attach($nodesByPath, $findingsByNode, $context);

        // 3. Compute debt per node
        $this->htmlDebtCalculator->computeDebt($findingsByNode, $nodesByPath);

        // 4. Compute violationCountTotal and aggregate debt bottom-up
        $this->htmlDebtCalculator->aggregateBottomUp($root);

        // 5. Override root debt with report-level total when available.
        // Bottom-up aggregation misses file-level/project-level findings
        // that aren't partitioned into tree nodes. Report's techDebtMinutes
        // (set by SummaryEnricher) covers all findings.
        if ($report->techDebtMinutes > 0) {
            $root->debtMinutes = $report->techDebtMinutes;
        }

        // 6. Build summary
        $summary = $this->buildSummary($report, $root, $nodesByPath);

        // 7. Build computed metric definitions
        $definitions = $this->buildComputedMetricDefinitions();

        // 8. Build project metadata
        $project = $this->buildProjectMetadata($scopedReporting, $projectName);

        return [
            'project' => $project,
            'tree' => $root->toArray(),
            'summary' => $summary,
            'computedMetricDefinitions' => (object) $definitions,
        ];
    }

    private function buildTree(Report $report, ?string $projectName = null): HtmlTreeNode
    {
        $root = new HtmlTreeNode($projectName ?? '<project>', '', SymbolLevel::Project->value);

        if ($report->metrics === null) {
            return $root;
        }

        $metrics = $report->metrics;

        // Attach project-level metrics
        $projectBag = $metrics->get(SymbolPath::forProject());
        $root->metrics = $this->filterMetrics($projectBag->all());

        // Build namespace hierarchy
        /** @var array<string, HtmlTreeNode> $nodesByPath */
        $nodesByPath = [];
        $namespaces = $metrics->getNamespaces();

        foreach ($namespaces as $namespace) {
            if ($namespace === '') {
                continue; // Empty namespace classes go to "(no namespace)" node
            }
            $this->ensureNamespaceChain($root, $namespace, $nodesByPath, $metrics);
        }

        // Build file LOC index: file path -> loc value
        $fileLoc = $this->buildFileLocIndex($metrics);

        // Add classes
        foreach ($metrics->all(SymbolLevel::Class_) as $symbolInfo) {
            $symbolPath = $symbolInfo->symbolPath;
            $namespace = $symbolPath->namespace ?? '';
            $className = $symbolPath->type ?? '';

            if ($className === '') {
                continue;
            }

            // Determine parent node
            $parentNode = $namespace !== ''
                ? ($nodesByPath[$namespace] ?? $this->ensureNamespaceChain($root, $namespace, $nodesByPath, $metrics))
                : $this->getNoNamespaceNode($root, $nodesByPath);

            $classNode = new HtmlTreeNode($className, $symbolPath->toString(), SymbolLevel::Class_->value);
            $classBag = $metrics->get($symbolPath);
            $classNode->metrics = $this->filterMetrics($classBag->all());

            // Class-level MetricBag doesn't have LOC — get it from the file
            if (!isset($classNode->metrics[MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)]) && $symbolInfo->file !== null) {
                $loc = $fileLoc[$symbolInfo->file->value()] ?? null;
                if ($loc !== null) {
                    $classNode->metrics[MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)] = $loc;
                }
            }

            $parentNode->children[] = $classNode;
        }

        // Aggregate metrics bottom-up for intermediate namespace nodes
        $this->metricAggregator->aggregateBottomUp($root);

        return $root;
    }

    /**
     * Ensures the full namespace chain exists in the tree.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     */
    private function ensureNamespaceChain(
        HtmlTreeNode $root,
        string $namespace,
        array &$nodesByPath,
        MetricRepositoryInterface $metrics,
    ): HtmlTreeNode {
        if (isset($nodesByPath[$namespace])) {
            return $nodesByPath[$namespace];
        }

        $parts = explode('\\', $namespace);
        $currentPath = '';
        $parentNode = $root;

        foreach ($parts as $part) {
            $currentPath = $currentPath === '' ? $part : $currentPath . '\\' . $part;

            if (!isset($nodesByPath[$currentPath])) {
                $node = new HtmlTreeNode($part, $currentPath, SymbolLevel::Namespace_->value);

                // Attach namespace metrics if available
                $nsPath = SymbolPath::forNamespace($currentPath);
                if ($metrics->has($nsPath)) {
                    $nsBag = $metrics->get($nsPath);
                    $node->metrics = $this->filterMetrics($nsBag->all());
                }

                $parentNode->children[] = $node;
                $nodesByPath[$currentPath] = $node;
            }

            $parentNode = $nodesByPath[$currentPath];
        }

        return $parentNode;
    }

    /**
     * Gets or creates the synthetic "(no namespace)" node.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     */
    private function getNoNamespaceNode(HtmlTreeNode $root, array &$nodesByPath): HtmlTreeNode
    {
        if (isset($nodesByPath[self::NO_NAMESPACE_LABEL])) {
            return $nodesByPath[self::NO_NAMESPACE_LABEL];
        }

        $node = new HtmlTreeNode(self::NO_NAMESPACE_LABEL, self::NO_NAMESPACE_LABEL, SymbolLevel::Namespace_->value);
        $root->children[] = $node;
        $nodesByPath[self::NO_NAMESPACE_LABEL] = $node;

        return $node;
    }

    /**
     * Builds an index of file path -> LOC value from file-level metrics.
     *
     * @return array<string, int|float>
     */
    private function buildFileLocIndex(MetricRepositoryInterface $metrics): array
    {
        $index = [];

        foreach ($metrics->all(SymbolLevel::File) as $symbolInfo) {
            if ($symbolInfo->file === null) {
                continue;
            }

            $bag = $metrics->get($symbolInfo->symbolPath);
            $loc = $bag->get(MetricName::SIZE_LOC);
            if ($loc !== null) {
                $index[$symbolInfo->file->value()] = $loc;
            }
        }

        return $index;
    }

    /**
     * Filters metrics: removes internal keys (containing ':') and replaces NAN/INF with null.
     *
     * @param array<string, int|float> $metrics
     *
     * @return array<string, int|float|null>
     */
    private function filterMetrics(array $metrics): array
    {
        $result = [];

        foreach ($metrics as $name => $value) {
            if (str_contains($name, ':')) {
                continue;
            }

            if (\is_float($value) && (is_nan($value) || is_infinite($value))) {
                $result[$name] = null;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Builds an index of all tree nodes by their path.
     *
     * @return array<string, HtmlTreeNode>
     */
    private function indexNodes(HtmlTreeNode $root): array
    {
        $index = [];
        $this->indexNodesRecursive($root, $index);

        return $index;
    }

    /**
     * @param array<string, HtmlTreeNode> $index
     */
    private function indexNodesRecursive(HtmlTreeNode $node, array &$index): void
    {
        $index[$node->path] = $node;

        foreach ($node->children as $child) {
            $this->indexNodesRecursive($child, $index);
        }
    }

    /**
     * Builds the summary section.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     *
     * @return array<string, mixed>
     */
    private function buildSummary(Report $report, HtmlTreeNode $root, array $nodesByPath): array
    {
        $classCount = 0;
        foreach ($nodesByPath as $node) {
            if ($node->type === SymbolLevel::Class_->value) {
                $classCount++;
            }
        }

        // Extract health scores from project-level metrics
        $healthScores = [];
        foreach ($root->metrics as $name => $value) {
            if (str_starts_with($name, 'health.')) {
                $healthScores[$name] = $value;
            }
        }

        return [
            'totalFiles' => $report->filesAnalyzed,
            'totalClasses' => $classCount,
            'totalViolations' => $report->getTotalFindings(),
            'totalDebtMinutes' => $root->debtMinutes,
            'healthScores' => (object) $healthScores,
        ];
    }

    /**
     * Builds project metadata.
     *
     * @return array<string, mixed>
     */
    private function buildProjectMetadata(bool $scopedReporting, ?string $projectName = null): array
    {
        return [
            'name' => $projectName ?? InstalledVersions::getRootPackage()['name'] ?? 'unknown',
            'generatedAt' => gmdate('c'),
            'qmxVersion' => Version::get(),
            'scopedReporting' => $scopedReporting,
        ];
    }

    /**
     * Builds computed metric definitions for health scores.
     *
     * @return array<string, array{description: string, scale: list<int>, inverted: bool}>
     */
    private function buildComputedMetricDefinitions(): array
    {
        $definitions = [];

        foreach ($this->definitionCatalog->all() as $def) {
            if (!str_starts_with($def->name, 'health.')) {
                continue;
            }

            $definitions[$def->name] = [
                'description' => $def->description,
                'scale' => [0, 100],
                'inverted' => $def->inverted,
            ];
        }

        return $definitions;
    }
}
