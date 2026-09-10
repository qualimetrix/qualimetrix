<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Every name {@see CouplingCollector} asks its framework predicate about, in
 * the FQCN spelling that predicate builds — the classification a
 * `coupling.frameworkNamespaces` prefix can actually perform.
 *
 * **Its own subject is the classification, not the metric.** Two consumers ask
 * different things of it: the collector performs the classification while
 * computing coupling, and
 * {@see UnmatchedFrameworkNamespaceRule} asks which names it reached, to say
 * which configured prefix reached none of them. The rule used to derive that
 * set for itself, from both ends of every edge, and so believed a prefix
 * classified a class the collector never offers the predicate: the class at the
 * tail of an edge is only ever classified when the class at its head is one
 * this run measured, and a class whose sole edge points at unmeasured code is
 * never asked about at all. A prefix over such a class moves neither
 * `coupling.cbo-app` nor `coupling.ce-framework`, and a channel that called it
 * bound was telling the author it did.
 *
 * The two loops below are the two positions in
 * `CouplingCollector::computeClassMetrics()` where the predicate is called;
 * `UnmatchedFrameworkNamespaceRuleTest` pins that there are no others, because
 * nothing in the language keeps this a mirror.
 */
final readonly class FrameworkClassificationSites
{
    /**
     * @param callable(SymbolPath): bool $measured whether the run holds metrics for a class — the
     *                                             collector's own `$repository->has()` question, passed
     *                                             as the predicate rather than the whole repository so
     *                                             this class depends on the one answer it needs
     *
     * @return list<string>
     */
    public static function names(DependencyGraphInterface $graph, callable $measured): array
    {
        $names = [];

        foreach ($graph->getAllClasses() as $symbolPath) {
            if (!$measured($symbolPath)) {
                continue;
            }

            foreach ($graph->getClassDependencies($symbolPath) as $dep) {
                $names[] = self::candidateName($dep->targetLogical());
            }

            foreach ($graph->getClassDependents($symbolPath) as $dep) {
                $names[] = self::candidateName($dep->sourceLogical());
            }
        }

        return array_values(array_filter($names, static fn(string $name): bool => $name !== ''));
    }

    /**
     * The FQCN spelling `CouplingCollector::isFrameworkSymbol()` builds before
     * matching. Repeated rather than shared: that method is private to the
     * collector's own walk, and exposing it would make the predicate itself a
     * public surface for the sake of a string.
     */
    private static function candidateName(SymbolPath $symbolPath): string
    {
        $namespace = $symbolPath->namespace ?? '';
        $type = $symbolPath->type ?? '';

        if ($namespace === '' && $type === '') {
            return '';
        }

        return $namespace !== '' ? $namespace . '\\' . $type : $type;
    }
}
