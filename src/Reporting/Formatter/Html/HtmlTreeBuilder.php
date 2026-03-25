<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Composer\InstalledVersions;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

/**
 * Builds the complete data structure for the HTML report.
 *
 * Constructs a hierarchical tree of namespaces and classes with metrics,
 * violations, and debt information attached to each node.
 */
final class HtmlTreeBuilder
{
    private const string NO_NAMESPACE_LABEL = '(no namespace)';

    public function __construct(
        private readonly DebtCalculator $debtCalculator,
    ) {}

    /**
     * Builds the complete data structure for the HTML report.
     *
     * @return array<string, mixed> JSON-ready structure with keys: project, tree, summary, computedMetricDefinitions
     */
    public function build(Report $report, FormatterContext $context, bool $partialAnalysis = false): array
    {
        // 1. Build the tree from metrics
        $projectNameOpt = $context->getOption('project-name');
        $projectName = $projectNameOpt !== '' ? $projectNameOpt : null;
        $root = $this->buildTree($report, $projectName);

        // 2. Attach violations to tree nodes
        $nodesByPath = $this->indexNodes($root);
        $violationsByNode = $this->partitionViolations($report->violations, $nodesByPath);
        $this->attachViolations($nodesByPath, $violationsByNode, $context);

        // 3. Compute debt per node
        $this->computeDebt($violationsByNode, $nodesByPath);

        // 4. Compute violationCountTotal bottom-up
        $this->computeViolationCountTotal($root);

        // 5. Override root debt with report-level total when available.
        // Bottom-up aggregation misses file-level/project-level violations
        // that aren't partitioned into tree nodes. Report's techDebtMinutes
        // (set by SummaryEnricher) covers all violations.
        if ($report->techDebtMinutes > 0) {
            $root->debtMinutes = $report->techDebtMinutes;
        }

        // 6. Build summary
        $summary = $this->buildSummary($report, $root, $nodesByPath);

        // 6. Build computed metric definitions
        $definitions = $this->buildComputedMetricDefinitions();

        // 7. Build project metadata
        $project = $this->buildProjectMetadata($partialAnalysis, $projectName);

        return [
            'project' => $project,
            'tree' => $root->toArray(),
            'summary' => $summary,
            'computedMetricDefinitions' => (object) $definitions,
        ];
    }

    private function buildTree(Report $report, ?string $projectName = null): HtmlTreeNode
    {
        $root = new HtmlTreeNode($projectName ?? '<project>', '', 'project');

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
        foreach ($metrics->all(SymbolType::Class_) as $symbolInfo) {
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

            $classNode = new HtmlTreeNode($className, $symbolPath->toString(), 'class');
            $classBag = $metrics->get($symbolPath);
            $classNode->metrics = $this->filterMetrics($classBag->all());

            // Class-level MetricBag doesn't have LOC — get it from the file
            if (!isset($classNode->metrics['loc.sum']) && $symbolInfo->file !== '') {
                $loc = $fileLoc[$symbolInfo->file] ?? null;
                if ($loc !== null) {
                    $classNode->metrics['loc.sum'] = $loc;
                }
            }

            $parentNode->children[] = $classNode;
        }

        // Aggregate metrics bottom-up for intermediate namespace nodes
        $this->aggregateMetricsBottomUp($root);

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
                $node = new HtmlTreeNode($part, $currentPath, 'namespace');

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

        $node = new HtmlTreeNode(self::NO_NAMESPACE_LABEL, self::NO_NAMESPACE_LABEL, 'namespace');
        $root->children[] = $node;
        $nodesByPath[self::NO_NAMESPACE_LABEL] = $node;

        return $node;
    }

    /**
     * Builds an index of file path -> LOC value from file-level metrics.
     *
     * Class-level MetricBags don't contain LOC (it's a file-level metric),
     * so we need this index to assign LOC to class nodes.
     *
     * @return array<string, int|float>
     */
    private function buildFileLocIndex(MetricRepositoryInterface $metrics): array
    {
        $index = [];

        foreach ($metrics->all(SymbolType::File) as $symbolInfo) {
            $bag = $metrics->get($symbolInfo->symbolPath);
            $loc = $bag->get('loc');
            if ($loc !== null) {
                $index[$symbolInfo->file] = $loc;
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
     * Aggregates metrics bottom-up for intermediate namespace nodes.
     *
     * - loc.sum: summed from children
     * - health.*: weighted average by loc.sum (so larger modules weigh more)
     */
    private function aggregateMetricsBottomUp(HtmlTreeNode $node): void
    {
        // Recurse into children first (post-order)
        foreach ($node->children as $child) {
            $this->aggregateMetricsBottomUp($child);
        }

        if ($node->children === []) {
            return;
        }

        // Aggregate loc.sum
        if (!isset($node->metrics['loc.sum'])) {
            $sum = 0;
            $hasValue = false;

            foreach ($node->children as $child) {
                $childLoc = $child->metrics['loc.sum'] ?? null;
                if ($childLoc !== null) {
                    $sum += $childLoc;
                    $hasValue = true;
                }
            }

            if ($hasValue) {
                $node->metrics['loc.sum'] = $sum;
            }
        }

        // Aggregate health.* scores via LOC-weighted average
        $healthKeys = ['health.overall', 'health.complexity', 'health.cohesion',
            'health.coupling', 'health.typing', 'health.maintainability'];

        foreach ($healthKeys as $key) {
            if (isset($node->metrics[$key])) {
                continue; // Already has this metric from the repository
            }

            $weightedSum = 0.0;
            $totalWeight = 0.0;

            foreach ($node->children as $child) {
                $score = $child->metrics[$key] ?? null;
                if ($score === null) {
                    continue;
                }

                $weight = (float) ($child->metrics['loc.sum'] ?? 1);
                $weightedSum += $score * $weight;
                $totalWeight += $weight;
            }

            if ($totalWeight > 0) {
                $node->metrics[$key] = $weightedSum / $totalWeight;
            }
        }
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
     * Partitions violations by tree node path.
     *
     * Method-level violations are attached to the parent class node.
     * Class-level violations are attached to the class node.
     * Namespace-level violations are attached to the namespace node.
     * File-level / unresolvable violations are skipped.
     *
     * @param list<Violation> $violations
     * @param array<string, HtmlTreeNode> $nodesByPath
     *
     * @return array<string, list<Violation>> node path -> violations
     */
    private function partitionViolations(array $violations, array $nodesByPath): array
    {
        /** @var array<string, list<Violation>> $result */
        $result = [];

        foreach ($violations as $violation) {
            $symbolPath = $violation->symbolPath;
            $type = $symbolPath->getType();

            $nodePath = match ($type) {
                SymbolType::Method, SymbolType::Function_ => $this->resolveClassPath($symbolPath),
                SymbolType::Class_ => $symbolPath->toString(),
                SymbolType::Namespace_ => $symbolPath->namespace ?? '',
                default => null,
            };

            if ($nodePath === null || !isset($nodesByPath[$nodePath])) {
                // Try attaching to namespace for method/class violations whose class node doesn't exist
                if ($type === SymbolType::Method || $type === SymbolType::Class_) {
                    $nsPath = $symbolPath->namespace ?? '';
                    if ($nsPath !== '' && isset($nodesByPath[$nsPath])) {
                        $result[$nsPath][] = $violation;

                        continue;
                    }
                }

                continue;
            }

            $result[$nodePath][] = $violation;
        }

        return $result;
    }

    /**
     * Resolves a method/function SymbolPath to its parent class path string.
     */
    private function resolveClassPath(SymbolPath $symbolPath): ?string
    {
        if ($symbolPath->type === null) {
            return null;
        }

        $classPath = SymbolPath::forClass($symbolPath->namespace ?? '', $symbolPath->type);

        return $classPath->toString();
    }

    /**
     * Attaches formatted violation data to tree nodes.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param array<string, list<Violation>> $violationsByNode
     */
    private function attachViolations(
        array $nodesByPath,
        array $violationsByNode,
        FormatterContext $context,
    ): void {
        foreach ($violationsByNode as $nodePath => $violations) {
            if (!isset($nodesByPath[$nodePath])) {
                continue;
            }

            $node = $nodesByPath[$nodePath];

            foreach ($violations as $violation) {
                $metricValue = $violation->metricValue;
                if ($metricValue !== null && \is_float($metricValue) && (is_nan($metricValue) || is_infinite($metricValue))) {
                    $metricValue = null;
                }

                $node->violations[] = [
                    'ruleName' => $violation->ruleName,
                    'violationCode' => $violation->violationCode,
                    'message' => $violation->message,
                    'severity' => $violation->severity->value,
                    'metricValue' => $metricValue,
                    'symbolPath' => $violation->symbolPath->toString(),
                    'file' => $violation->location->isNone()
                        ? ''
                        : $context->relativizePath($violation->location->file),
                    'line' => $violation->location->line,
                ];
            }
        }
    }

    /**
     * Computes debt per node from partitioned violations.
     *
     * @param array<string, list<Violation>> $violationsByNode
     * @param array<string, HtmlTreeNode> $nodesByPath
     */
    private function computeDebt(
        array $violationsByNode,
        array $nodesByPath,
    ): void {
        foreach ($violationsByNode as $nodePath => $violations) {
            if (!isset($nodesByPath[$nodePath])) {
                continue;
            }

            $debt = $this->debtCalculator->calculate($violations);
            $nodesByPath[$nodePath]->debtMinutes = $debt->totalMinutes;
        }
    }

    /**
     * Computes violationCountTotal bottom-up (post-order traversal).
     */
    private function computeViolationCountTotal(HtmlTreeNode $node): int
    {
        $total = \count($node->violations);

        foreach ($node->children as $child) {
            $total += $this->computeViolationCountTotal($child);
        }

        $node->violationCountTotal = $total;

        // Also aggregate debt bottom-up
        if ($node->children !== []) {
            $debtSum = 0;
            foreach ($node->children as $child) {
                $debtSum += $child->debtMinutes;
            }
            // Node's own debt is already set from its own violations.
            // Add children's debt.
            $node->debtMinutes += $debtSum;
        }

        return $total;
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
            if ($node->type === 'class') {
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
            'totalViolations' => $report->getTotalViolations(),
            'totalDebtMinutes' => $root->debtMinutes,
            'healthScores' => (object) $healthScores,
        ];
    }

    /**
     * Builds project metadata.
     *
     * @return array<string, mixed>
     */
    private function buildProjectMetadata(bool $partialAnalysis, ?string $projectName = null): array
    {
        return [
            'name' => $projectName ?? InstalledVersions::getRootPackage()['name'] ?? 'unknown',
            'generatedAt' => gmdate('c'),
            'qmxVersion' => InstalledVersions::getRootPackage()['pretty_version'] ?? 'dev',
            'partialAnalysis' => $partialAnalysis,
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

        foreach (ComputedMetricDefinitionHolder::getDefinitions() as $def) {
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
