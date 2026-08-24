<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\FormatterContext;

/**
 * Partitions findings by tree node and attaches formatted finding data.
 *
 * Method-level findings are attached to the parent class node.
 * Class-level findings are attached to the class node.
 * Namespace-level findings are attached to the namespace node.
 * File-level / unresolvable findings are skipped.
 *
 * @internal
 */
final readonly class HtmlFindingPartitioner
{
    /**
     * Partitions findings by tree node path.
     *
     * @param list<Finding> $findings
     * @param array<string, HtmlTreeNode> $nodesByPath
     *
     * @return array<string, list<Finding>> node path -> findings
     */
    public function partition(array $findings, array $nodesByPath): array
    {
        /** @var array<string, list<Finding>> $result */
        $result = [];

        foreach ($findings as $finding) {
            $symbolPath = $finding->symbolPath;
            $type = $symbolPath->getType();

            $nodePath = match ($type) {
                SymbolType::Method, SymbolType::Function_ => $this->resolveClassPath($symbolPath),
                SymbolType::Class_ => $symbolPath->toString(),
                SymbolType::Namespace_ => $symbolPath->namespace ?? '',
                default => null,
            };

            if ($nodePath === null || !isset($nodesByPath[$nodePath])) {
                // Try attaching to namespace for method/class findings whose class node doesn't exist
                if ($type === SymbolType::Method || $type === SymbolType::Class_) {
                    $nsPath = $symbolPath->namespace ?? '';
                    if ($nsPath !== '' && isset($nodesByPath[$nsPath])) {
                        $result[$nsPath][] = $finding;

                        continue;
                    }
                }

                continue;
            }

            $result[$nodePath][] = $finding;
        }

        return $result;
    }

    /**
     * Attaches formatted finding data to tree nodes.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param array<string, list<Finding>> $findingsByNode
     *
     * @qmx-threshold complexity.cyclomatic warning=11 error=11 — Finite attachment projection keeps node lookup, magnitude normalization, and payload fields together.
     */
    public function attach(
        array $nodesByPath,
        array $findingsByNode,
        FormatterContext $context,
    ): void {
        foreach ($findingsByNode as $nodePath => $findings) {
            if (!isset($nodesByPath[$nodePath])) {
                continue;
            }

            $node = $nodesByPath[$nodePath];

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
                        ? ''
                        : $context->relativizePath($finding->location->file),
                    'line' => $finding->location->line,
                ];
            }
        }
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
}
