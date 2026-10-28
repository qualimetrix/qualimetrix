<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Contract\Formatting\FormatterContext;
use Qualimetrix\Reporting\FindingProjection\FindingNamespaces;
use Qualimetrix\Reporting\FindingPublication\FindingRecord;

/**
 * Partitions findings by tree node and attaches formatted finding data.
 *
 * Every finding lands on exactly one node, because the report's summary
 * counts every finding and the tree's totals have to agree with it:
 *
 * - a callable finding on its class node, a class finding on its own;
 * - a global function finding on its namespace node;
 * - a namespace finding on its namespace node;
 * - a file finding on the class node of the one class that file declares,
 *   otherwise on the node of the namespace its record names — the same
 *   attribution JSON and text group it by;
 * - and whatever has no such node — a project finding, a symbol whose node
 *   the metrics never produced — on the nearest enclosing node, the project
 *   root at the latest.
 *
 * A file finding whose namespace has no node of its own (a namespace only
 * class-free files open) lands on its nearest ancestor namespace that has one.
 * A file opening several namespaces lands on their deepest common ancestor,
 * and on the project root when they share none or one of them is the global
 * namespace.
 *
 * @internal
 */
final readonly class HtmlFindingPartitioner
{
    /** The project root's node path. */
    public const string ROOT = '';

    private const string NO_NAMESPACE = '(no namespace)';

    public function __construct(private FindingRecord $record) {}

    /**
     * Partitions findings by tree node path.
     *
     * @param list<Finding> $findings
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param FileNamespaceIndex $fileNamespaces the index the finding records are attributed by
     * @param ?MetricRepositoryInterface $metrics where the classes each file declares are read from
     *
     * @return array<string, list<Finding>> node path -> findings
     */
    public function partition(array $findings, array $nodesByPath, FileNamespaceIndex $fileNamespaces, ?MetricRepositoryInterface $metrics = null): array
    {
        /** @var array<string, list<Finding>> $result */
        $result = [];
        $soleClassByFile = self::soleClassByFile($metrics);
        $classByCallable = self::classByCallable($metrics);

        foreach ($findings as $finding) {
            $result[$this->nodePathOf($finding, $nodesByPath, $fileNamespaces, $soleClassByFile, $classByCallable)][] = $finding;
        }

        return $result;
    }

    /**
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param array<string, string> $soleClassByFile
     * @param array<string, string> $classByCallable
     */
    private function nodePathOf(Finding $finding, array $nodesByPath, FileNamespaceIndex $fileNamespaces, array $soleClassByFile, array $classByCallable): string
    {
        foreach ($this->candidatePaths($finding, $fileNamespaces, $soleClassByFile, $classByCallable) as $candidate) {
            if (isset($nodesByPath[$candidate])) {
                return $candidate;
            }
        }

        if (!isset($nodesByPath[self::ROOT])) {
            throw new LogicException(\sprintf(
                'No tree node can hold the finding on "%s": the project root node is missing.',
                $finding->symbolPath->toString(),
            ));
        }

        return self::ROOT;
    }

    /**
     * File path -> class node path, for files declaring exactly one named
     * class: the one place a file-level finding can be shown more precisely
     * than on the project root.
     *
     * @return array<string, string>
     */
    private static function soleClassByFile(?MetricRepositoryInterface $metrics): array
    {
        if ($metrics === null) {
            return [];
        }

        $classesByFile = [];
        foreach ($metrics->allClassDeclarations() as $symbolInfo) {
            if ($symbolInfo->file === null || ($symbolInfo->symbolPath->type ?? '') === '') {
                continue;
            }

            $classesByFile[$symbolInfo->file->value()][] = $symbolInfo->subject?->toCanonical()
                ?? throw new LogicException('HTML class file binding requires an exact declaration subject');
        }

        return array_map(
            static fn(array $classes): string => $classes[0],
            array_filter($classesByFile, static fn(array $classes): bool => \count($classes) === 1),
        );
    }

    /** @return array<string, string> */
    private static function classByCallable(?MetricRepositoryInterface $metrics): array
    {
        if ($metrics === null) {
            return [];
        }
        $owners = [];
        foreach ($metrics->allCallables() as $info) {
            if ($info->subject !== null && $info->classAggregationOwner !== null) {
                $owners[$info->subject->toCanonical()] = \Qualimetrix\Core\Symbol\MetricSubject::declaration($info->classAggregationOwner)->toCanonical();
            }
        }

        return $owners;
    }

    /**
     * Node paths to try, most specific first.
     *
     * @param array<string, string> $soleClassByFile
     * @param array<string, string> $classByCallable
     *
     * @return list<string>
     */
    private function candidatePaths(Finding $finding, FileNamespaceIndex $fileNamespaces, array $soleClassByFile, array $classByCallable): array
    {
        $symbolPath = $finding->symbolPath;
        $namespace = $symbolPath->namespace ?? '';
        $namespaceNode = $namespace === '' ? self::NO_NAMESPACE : $namespace;

        return match ($symbolPath->getType()) {
            SymbolType::Method => [$classByCallable[$finding->subject->toCanonical()] ?? '', $namespaceNode],
            SymbolType::Class_ => [$finding->subject->toCanonical(), $namespaceNode],
            SymbolType::Function_, SymbolType::Namespace_ => [$namespaceNode],
            SymbolType::File => [
                ...self::fileCandidatePaths($symbolPath->toString(), $soleClassByFile),
                ...self::enclosingNamespacePaths(FindingNamespaces::of($finding, $fileNamespaces)),
            ],
            default => [],
        };
    }

    /**
     * @param array<string, string> $soleClassByFile
     *
     * @return list<string>
     */
    private static function fileCandidatePaths(string $filePath, array $soleClassByFile): array
    {
        return isset($soleClassByFile[$filePath]) ? [$soleClassByFile[$filePath]] : [];
    }

    /**
     * The deepest namespace enclosing all of `$namespaces`, then its
     * ancestors.
     *
     * @param list<string> $namespaces
     *
     * @return list<string>
     */
    private static function enclosingNamespacePaths(array $namespaces): array
    {
        if ($namespaces === ['']) {
            return [self::NO_NAMESPACE];
        }
        if ($namespaces === [] || \in_array('', $namespaces, true)) {
            return [];
        }

        $common = explode('\\', $namespaces[0]);
        foreach (\array_slice($namespaces, 1) as $namespace) {
            $parts = explode('\\', $namespace);
            $depth = 0;
            while ($depth < \count($common) && $depth < \count($parts) && $common[$depth] === $parts[$depth]) {
                ++$depth;
            }
            $common = \array_slice($common, 0, $depth);
        }

        $paths = [];
        for ($depth = \count($common); $depth > 0; --$depth) {
            $paths[] = implode('\\', \array_slice($common, 0, $depth));
        }

        return $paths;
    }

    /**
     * Attaches formatted finding data to tree nodes.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param array<string, list<Finding>> $findingsByNode
     */
    public function attach(
        array $nodesByPath,
        array $findingsByNode,
        FormatterContext $context,
        FileNamespaceIndex $fileNamespaces,
    ): void {
        foreach ($findingsByNode as $nodePath => $findings) {
            $node = $nodesByPath[$nodePath] ?? throw new LogicException(\sprintf(
                'Findings were partitioned to "%s", which is not a node of this tree.',
                $nodePath,
            ));

            foreach ($findings as $finding) {
                $node->findings[] = $this->record->of($finding, $context, $fileNamespaces);
            }
        }
    }
}
