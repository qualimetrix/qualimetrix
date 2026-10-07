<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;

/**
 * Exports dependency graphs to JSON format.
 *
 * Features:
 * - Aggregated edges (unique from->to pairs with all types collected)
 * - Node list with FQN and namespace
 * - Statistics (node/edge counts)
 * - Namespace filtering (include/exclude) via the shared {@see NamespaceFilter}
 */
final class JsonGraphExporter
{
    /**
     * @param list<NamespacePattern>|null $includeNamespaces
     * @param list<NamespacePattern> $excludeNamespaces
     */
    public function __construct(
        private readonly ?array $includeNamespaces = null,
        private readonly array $excludeNamespaces = [],
    ) {}

    public function export(DependencyGraphInterface $graph): string
    {
        $classes = (new NamespaceFilter($this->includeNamespaces, $this->excludeNamespaces))->apply($graph->getAllClasses());
        $nodes = self::nodes($classes);
        $edges = self::edges(self::edgeMap($graph, self::classSet($classes)));
        $identity = ProductIdentity::identity();

        $result = [
            // Not ProductIdentity::meta(): this envelope's `version` is the
            // graph format version and `package` is its own literal, not the
            // tool's identity — the same treatment MetricsJsonFormatter gives
            // its own `version`. Only the two keys this envelope does not
            // already carry come from ProductIdentity.
            'meta' => [
                'version' => '1.0.0',
                'package' => 'qmx',
                'timestamp' => gmdate('c'),
                'docs' => $identity['docs'],
                'llmsTxt' => $identity['llmsTxt'],
            ],
            'statistics' => [
                'nodeCount' => \count($nodes),
                'edgeCount' => \count($edges),
            ],
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        // A class or method name can carry a byte `nikic/php-parser` accepts
        // inside an identifier but that is not valid UTF-8 — see
        // {@see PublishedUtf8}. Without this repair, `json_encode`'s
        // `JSON_THROW_ON_ERROR` would throw here and the whole export would
        // fail on an otherwise complete analysis.
        return PublishedUtf8::encodeJsonObject($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @param list<SymbolPath> $classes
     *
     * @return list<array{fqn: string, namespace: string}>
     */
    private static function nodes(array $classes): array
    {
        $nodes = array_map(
            static fn(SymbolPath $class): array => [
                'fqn' => $class->toString(),
                'namespace' => $class->namespace ?? '',
            ],
            $classes,
        );
        usort($nodes, static fn(array $a, array $b): int => $a['fqn'] <=> $b['fqn']);

        return $nodes;
    }

    /**
     * @param list<SymbolPath> $classes
     *
     * @return array<string, true>
     */
    private static function classSet(array $classes): array
    {
        $set = [];
        foreach ($classes as $class) {
            $set[$class->toCanonical()] = true;
        }

        return $set;
    }

    /**
     * @param array<string, true> $classSet
     *
     * @return array<string, array{from: string, to: string, types: array<string, true>, shape: array<string, array<string, true>>, count: int}>
     */
    private static function edgeMap(DependencyGraphInterface $graph, array $classSet): array
    {
        $edges = [];
        foreach ($graph->getAllDependencies() as $dependency) {
            $source = $dependency->sourceLogical()->toCanonical();
            $target = $dependency->targetLogical()->toCanonical();
            if (!isset($classSet[$source], $classSet[$target])) {
                continue;
            }
            $key = $source . '|' . $target;
            $edges[$key] ??= [
                'from' => $dependency->sourceLogical()->toString(),
                'to' => $dependency->targetLogical()->toString(),
                'types' => [],
                'shape' => [],
                'count' => 0,
            ];
            $edges[$key]['types'][$dependency->type->value] = true;
            if ($dependency->shape !== null) {
                $edges[$key]['shape'][$dependency->type->value][$dependency->shape->value] = true;
            }
            $edges[$key]['count']++;
        }

        return $edges;
    }

    /**
     * @param array<string, array{from: string, to: string, types: array<string, true>, shape: array<string, array<string, true>>, count: int}> $edgeMap
     *
     * @return list<array{from: string, to: string, types: list<string>, shape: object, count: int}>
     */
    private static function edges(array $edgeMap): array
    {
        $edges = [];
        foreach ($edgeMap as $edge) {
            $types = array_keys($edge['types']);
            sort($types);
            ksort($edge['shape']);
            $shape = [];
            foreach ($edge['shape'] as $type => $shapes) {
                $values = array_keys($shapes);
                sort($values);
                $shape[$type] = $values;
            }
            $edges[] = [
                'from' => $edge['from'],
                'to' => $edge['to'],
                'types' => $types,
                'shape' => (object) $shape,
                'count' => $edge['count'],
            ];
        }
        usort($edges, static function (array $a, array $b): int {
            $from = $a['from'] <=> $b['from'];

            return $from !== 0 ? $from : $a['to'] <=> $b['to'];
        });

        return $edges;
    }
}
