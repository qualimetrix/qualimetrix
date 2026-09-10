<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Counts what a `--namespace` or `--class` drill-down value binds to in a run.
 *
 * A drill-down value selects what the report shows; it never narrows what was
 * analyzed. So a value that names nothing produces the same empty report as a
 * subtree that is genuinely clean, and the count is what separates them: zero
 * bindings means the value pointed at nothing, not that nothing was wrong.
 *
 * The comparison must stay the one
 * {@see \Qualimetrix\Reporting\Filter\FindingFilter} makes, or a value could be
 * accepted here and filter nothing there: namespaces go through
 * {@see NamespaceMatcher::matchesSingle()}, classes are compared as the exact
 * `Namespace\Class` string the filter builds from a finding's symbol path.
 *
 * Stateless by construction — the run is an argument, not a collaborator — so a
 * caller that already holds the run needs no wiring to ask.
 */
final readonly class DrillDownBinding
{
    /**
     * Levels whose subjects carry a source namespace. `Project` is excluded
     * deliberately: its symbol path holds an internal sentinel where a
     * namespace would be, which a glob value would otherwise bind to.
     */
    private const array NAMED_LEVELS = [
        SymbolLevel::Callable,
        SymbolLevel::Class_,
        SymbolLevel::File,
        SymbolLevel::Namespace_,
    ];

    /**
     * Number of analyzed namespaces the pattern selects.
     */
    public function namespaceBindings(
        string $pattern,
        MetricRepositoryInterface $metrics,
        ?NamespaceTree $namespaceTree,
    ): int {
        $bindings = 0;

        foreach (array_keys($this->namespaceUniverse($metrics, $namespaceTree)) as $namespace) {
            if (NamespaceMatcher::matchesSingle($pattern, (string) $namespace)) {
                ++$bindings;
            }
        }

        return $bindings;
    }

    /**
     * Number of analyzed classes the fully qualified name selects — zero or one,
     * since the filter compares for equality.
     */
    public function classBindings(string $fqcn, MetricRepositoryInterface $metrics): int
    {
        return isset($this->classUniverse($metrics)[$fqcn]) ? 1 : 0;
    }

    /**
     * Size of the universe a namespace value is offered, for the refusal to name.
     */
    public function namespaceUniverseSize(MetricRepositoryInterface $metrics, ?NamespaceTree $namespaceTree): int
    {
        return \count($this->namespaceUniverse($metrics, $namespaceTree));
    }

    /**
     * Size of the universe a class value is offered, for the refusal to name.
     */
    public function classUniverseSize(MetricRepositoryInterface $metrics): int
    {
        return \count($this->classUniverse($metrics));
    }

    /**
     * @return array<string, true>
     */
    private function namespaceUniverse(MetricRepositoryInterface $metrics, ?NamespaceTree $namespaceTree): array
    {
        $universe = [];

        foreach (self::NAMED_LEVELS as $level) {
            foreach ($metrics->all($level) as $info) {
                $namespace = $info->symbolPath->namespace;
                if ($namespace !== null && $namespace !== '') {
                    $universe[$namespace] = true;
                }
            }
        }

        foreach ($metrics->getNamespaces() as $namespace) {
            $universe[$namespace] = true;
        }

        foreach ($namespaceTree?->getAllNamespaces() ?? [] as $namespace) {
            $universe[$namespace] = true;
        }

        // Intermediate namespaces that hold no symbols of their own are only
        // ever spelled out by the tree. Without them a glob value would be
        // refused for a subtree that exists whenever the tree is absent, so
        // they are synthesized rather than left to depend on it.
        foreach (array_keys($universe) as $namespace) {
            $parent = (string) $namespace;
            while (($cut = strrpos($parent, '\\')) !== false) {
                $parent = substr($parent, 0, $cut);
                $universe[$parent] = true;
            }
        }

        return $universe;
    }

    /**
     * @return array<string, true>
     */
    private function classUniverse(MetricRepositoryInterface $metrics): array
    {
        $universe = [];

        foreach (self::NAMED_LEVELS as $level) {
            foreach ($metrics->all($level) as $info) {
                $type = $info->symbolPath->type;
                if ($type === null) {
                    continue;
                }

                $namespace = $info->symbolPath->namespace ?? '';
                $universe[$namespace !== '' ? $namespace . '\\' . $type : $type] = true;
            }
        }

        return $universe;
    }
}
