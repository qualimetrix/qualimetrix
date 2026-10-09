<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Health\HealthCoverageNarrator;
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
 * - {@see HtmlDebtCalculator} — debt computation and aggregation
 */
final class HtmlTreeBuilder
{
    private const string NO_NAMESPACE_LABEL = '(no namespace)';

    private readonly HtmlFindingPartitioner $findingPartitioner;
    private readonly HtmlDebtCalculator $htmlDebtCalculator;

    public function __construct(
        private readonly DebtCalculator $debtCalculator,
        private readonly ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private readonly HtmlProjectMetadata $projectMetadata,
        \Qualimetrix\Reporting\Formatter\FindingRecord $findingRecord,
    ) {
        $this->findingPartitioner = new HtmlFindingPartitioner($findingRecord);
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
        $findingsByNode = $this->findingPartitioner->partition(
            $report->findings,
            $nodesByPath,
            $report->metrics,
        );
        $this->findingPartitioner->attach($nodesByPath, $findingsByNode, $context);

        // 3. Complete debt and finding totals; every finding is attached,
        // so the root's totals are the report's.
        $this->htmlDebtCalculator->calculate($root, $findingsByNode, $nodesByPath);

        // 4. Build summary
        $summary = $this->buildSummary($report, $root, $nodesByPath);

        // 5. Build computed metric definitions
        $definitions = $this->buildComputedMetricDefinitions();

        // 6. Build project metadata
        $project = $this->projectMetadata->of($scopedReporting, $projectName, $context->basePath);

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
                $this->getNoNamespaceNode($root, $nodesByPath, $metrics);

                continue;
            }
            $this->ensureNamespaceChain($root, $namespace, $nodesByPath, $metrics);
        }

        // Add classes
        foreach ($metrics->allClassDeclarations() as $symbolInfo) {
            $symbolPath = $symbolInfo->symbolPath;
            $namespace = $symbolPath->namespace ?? '';
            $className = $symbolPath->type ?? '';

            if ($className === '') {
                continue;
            }

            // Determine parent node
            $parentNode = $namespace !== ''
                ? ($nodesByPath[$namespace] ?? $this->ensureNamespaceChain($root, $namespace, $nodesByPath, $metrics))
                : $this->getNoNamespaceNode($root, $nodesByPath, $metrics);

            $subject = $symbolInfo->subject ?? throw new LogicException('HTML classes require exact declaration subjects');
            $classNode = new HtmlTreeNode($className, $symbolPath->toString(), SymbolLevel::Class_->value, $subject->toCanonical());
            $classBag = $metrics->getSubject($subject);
            $classNode->metrics = $this->filterMetrics($classBag->all());

            $parentNode->children[] = $classNode;
        }

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
    private function getNoNamespaceNode(HtmlTreeNode $root, array &$nodesByPath, MetricRepositoryInterface $metrics): HtmlTreeNode
    {
        if (isset($nodesByPath[self::NO_NAMESPACE_LABEL])) {
            return $nodesByPath[self::NO_NAMESPACE_LABEL];
        }

        $node = new HtmlTreeNode(self::NO_NAMESPACE_LABEL, self::NO_NAMESPACE_LABEL, SymbolLevel::Namespace_->value);
        $node->metrics = $this->filterMetrics($metrics->get(SymbolPath::forNamespace(''))->all());
        $root->children[] = $node;
        $nodesByPath[self::NO_NAMESPACE_LABEL] = $node;

        return $node;
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
        $index[$node->id] = $node;

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

        foreach ($report->healthScores as $name => $score) {
            $healthScores['health.' . $name] = $score->score;
        }

        return [
            'totalFiles' => $report->filesAnalyzed,
            'totalClasses' => $classCount,
            'totalViolations' => $report->getTotalFindings(),
            'totalDebtMinutes' => $root->debtMinutes,
            'healthScores' => (object) $healthScores,
            'healthDecomposition' => (object) array_combine(
                array_map(static fn(HealthScore $score): string => 'health.' . $score->name, array_values($report->healthScores)),
                array_map(static fn(HealthScore $score): array => array_map(static fn(DecompositionItem $item): array => [
                    'metric' => $item->metricKey,
                    'humanName' => $item->humanName,
                    'value' => $item->value,
                    'good' => $item->goodValue,
                    'direction' => $item->direction,
                    'coverage' => HealthCoverageNarrator::record($item->coverage),
                ], $score->decomposition), array_values($report->healthScores)),
            ),
            // ADR 0062 publishes coverage alongside the score, and this
            // surface used to carry the bare values alone: a score over a tenth
            // of the classes arrived indistinguishable from one over all of
            // them. Keyed by the same `health.*` name as the score beside it.
            'healthCoverage' => (object) array_combine(
                array_map(
                    static fn(HealthScore $score): string => 'health.' . $score->name,
                    array_values($report->healthScores),
                ),
                array_map(
                    static fn(HealthScore $score): array => HealthCoverageNarrator::record($score->coverage),
                    array_values($report->healthScores),
                ),
            ),
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
