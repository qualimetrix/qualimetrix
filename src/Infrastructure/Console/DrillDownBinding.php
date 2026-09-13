<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankedOffenderLevels;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
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
 * **A `--namespace` value is offered to that matcher twice, against two
 * different strings, so the universe here holds both.** The filter compares a
 * finding by its namespace and a worst offender by its whole canonical name;
 * `Demo\Alpha\*` misses the namespace `Demo\Alpha` and hits the offender
 * `Demo\Alpha\Widget`. Counting only namespaces refused such a value while the
 * report it produced was not empty. The universe is therefore the union, which
 * is a superset of the offenders any one run happens to rank: the question this
 * class answers is whether the value names anything analysed, not whether the
 * report came out non-empty — a subtree with nothing wrong in it must still
 * produce the empty report rather than a refusal.
 *
 * **The union is over the strings the filter really holds, not over every
 * string a symbol has.** A canonical name reaches a comparison only as a worst
 * offender's, and offenders are ranked for the levels
 * {@see RankedOffenderLevels} names — never for a File or a Callable. Adding
 * those canonical names too made a value that matches nothing else count as
 * bound: the refusal this class exists to raise was withheld and the empty
 * report went out unexplained, causing the same silent loss in the opposite
 * direction.
 *
 * Stateless by construction — the run is an argument, not a collaborator — so a
 * caller that already holds the run needs no wiring to ask.
 */
final readonly class DrillDownBinding
{
    /**
     * Levels whose subjects carry a source namespace — the strings
     * `FindingFilter::filterFindings()` compares a finding by.
     *
     * `Project` is excluded deliberately: its symbol path holds an internal
     * sentinel where a namespace would be, which a glob value would otherwise
     * bind to. `File` is absent because a File symbol path has no namespace at
     * all, so the level contributes nothing to either half of the universe —
     * listing it said the opposite of what the code did.
     */
    private const array NAMED_LEVELS = [
        SymbolLevel::Callable,
        SymbolLevel::Class_,
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
     * The strings the filter offers a `--namespace` value for one symbol of
     * the given level.
     *
     * `FindingFilter::filterWorstOffenders()` asks `NamespaceMatcher` about
     * `symbolPath->toString()` — the whole canonical name, class and member
     * included — while `filterFindings()` asks about the namespace alone. A
     * glob such as `Demo\Alpha\*` matches `Demo\Alpha\Widget` and not the
     * namespace `Demo\Alpha`, so a universe of namespaces alone refuses a
     * value that really does select offenders.
     *
     * The canonical name is added only for a level that can become a worst
     * offender. A File's canonical name is its path and a Callable's carries a
     * member; neither is ever on the right-hand side of a comparison, so
     * counting them would accept a value nothing downstream can use.
     *
     * @return list<string>
     */
    private static function comparedStringsOf(SymbolPath $symbolPath, SymbolLevel $level): array
    {
        $compared = [];
        $namespace = $symbolPath->namespace;

        if ($namespace !== null && $namespace !== '') {
            $compared[] = $namespace;
        }

        if (!\in_array($level, RankedOffenderLevels::LEVELS, true)) {
            return $compared;
        }

        $canonical = $symbolPath->toString();

        if ($canonical !== '') {
            $compared[] = $canonical;
        }

        return $compared;
    }

    /**
     * @return array<string, true>
     */
    private function namespaceUniverse(MetricRepositoryInterface $metrics, ?NamespaceTree $namespaceTree): array
    {
        $universe = [];

        foreach (self::NAMED_LEVELS as $level) {
            foreach ($metrics->all($level) as $info) {
                foreach (self::comparedStringsOf($info->symbolPath, $level) as $compared) {
                    $universe[$compared] = true;
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
