<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\FormatterContext;

/**
 * Partitions findings by tree node and attaches formatted finding data.
 *
 * Every finding lands on exactly one node, because the report's summary
 * counts every finding and the tree's totals have to agree with it:
 *
 * - a callable finding on its class node, a class finding on its own;
 * - a global function finding on its namespace node;
 * - a namespace finding on its namespace node;
 * - a file finding on the class node of the one class that file declares;
 * - and whatever has no such node — a project finding, a file declaring no
 *   class or several, a symbol whose node the metrics never produced — on the
 *   nearest enclosing node, the project root at the latest.
 *
 * @internal
 */
final readonly class HtmlFindingPartitioner
{
    /** The project root's node path. */
    public const string ROOT = '';

    /**
     * Partitions findings by tree node path.
     *
     * @param list<Finding> $findings
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param ?MetricRepositoryInterface $metrics where the classes each file declares are read from
     *
     * @return array<string, list<Finding>> node path -> findings
     */
    public function partition(array $findings, array $nodesByPath, ?MetricRepositoryInterface $metrics = null): array
    {
        /** @var array<string, list<Finding>> $result */
        $result = [];
        $soleClassByFile = self::soleClassByFile($metrics);

        foreach ($findings as $finding) {
            $result[$this->nodePathOf($finding, $nodesByPath, $soleClassByFile)][] = $finding;
        }

        return $result;
    }

    /**
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param array<string, string> $soleClassByFile
     */
    private function nodePathOf(Finding $finding, array $nodesByPath, array $soleClassByFile): string
    {
        foreach ($this->candidatePaths($finding, $soleClassByFile) as $candidate) {
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
        foreach ($metrics->all(SymbolLevel::Class_) as $symbolInfo) {
            if ($symbolInfo->file === null || ($symbolInfo->symbolPath->type ?? '') === '') {
                continue;
            }

            $classesByFile[$symbolInfo->file->value()][] = $symbolInfo->symbolPath->toString();
        }

        return array_map(
            static fn(array $classes): string => $classes[0],
            array_filter($classesByFile, static fn(array $classes): bool => \count($classes) === 1),
        );
    }

    /**
     * Node paths to try, most specific first.
     *
     * @param array<string, string> $soleClassByFile
     *
     * @return list<string>
     */
    private function candidatePaths(Finding $finding, array $soleClassByFile): array
    {
        $symbolPath = $finding->symbolPath;
        $namespace = $symbolPath->namespace ?? '';

        return match ($symbolPath->getType()) {
            SymbolType::Method => [SymbolPath::forClass($namespace, $symbolPath->type ?? '')->toString(), $namespace],
            SymbolType::Class_ => [$symbolPath->toString(), $namespace],
            SymbolType::Function_, SymbolType::Namespace_ => [$namespace],
            SymbolType::File => isset($soleClassByFile[$symbolPath->toString()])
                ? [$soleClassByFile[$symbolPath->toString()]]
                : [],
            default => [],
        };
    }

    /**
     * Attaches formatted finding data to tree nodes.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param array<string, list<Finding>> $findingsByNode
     *
     * @qmx-threshold complexity.ccn warning=11 error=11 — Finite attachment projection keeps node lookup, magnitude normalization, and payload fields together.
     */
    public function attach(
        array $nodesByPath,
        array $findingsByNode,
        FormatterContext $context,
    ): void {
        foreach ($findingsByNode as $nodePath => $findings) {
            $node = $nodesByPath[$nodePath] ?? throw new LogicException(\sprintf(
                'Findings were partitioned to "%s", which is not a node of this tree.',
                $nodePath,
            ));

            foreach ($findings as $finding) {
                $metricValue = $finding->metricValue;
                if ($metricValue !== null && \is_float($metricValue) && (is_nan($metricValue) || is_infinite($metricValue))) {
                    $metricValue = null;
                }

                $node->findings[] = [
                    'subject' => $finding->subject->toCanonical(),
                    'ruleName' => $finding->ruleName,
                    'violationCode' => $finding->code,
                    'message' => $finding->message,
                    'recommendation' => $finding->recommendation,
                    'severity' => $finding->severity->value,
                    'metricValue' => $metricValue,
                    'symbolPath' => $finding->symbolPath->toString(),
                    'occurrence' => $finding->occurrenceKey?->value,
                    'file' => $finding->location->file === null
                        ? null
                        : $context->relativizePath($finding->location->file),
                    'line' => $finding->location->line,
                ];
            }
        }
    }
}
