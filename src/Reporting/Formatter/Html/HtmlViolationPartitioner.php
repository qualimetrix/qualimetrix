<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\FormatterContext;

/**
 * Partitions violations by tree node and attaches formatted violation data.
 *
 * Method-level violations are attached to the parent class node.
 * Class-level violations are attached to the class node.
 * Namespace-level violations are attached to the namespace node.
 * File-level / unresolvable violations are skipped.
 *
 * @internal
 */
final readonly class HtmlViolationPartitioner
{
    /**
     * Partitions violations by tree node path.
     *
     * @param list<Violation> $violations
     * @param array<string, HtmlTreeNode> $nodesByPath
     *
     * @return array<string, list<Violation>> node path -> violations
     */
    public function partition(array $violations, array $nodesByPath): array
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
     * Attaches formatted violation data to tree nodes.
     *
     * @param array<string, HtmlTreeNode> $nodesByPath
     * @param array<string, list<Violation>> $violationsByNode
     */
    public function attach(
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
                    'recommendation' => $violation->recommendation,
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
