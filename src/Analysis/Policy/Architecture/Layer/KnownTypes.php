<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The types one run met at all: a declaration it analysed, a class or
 * interface PHP declares, or a name at either end of a dependency edge in the
 * declaration or the coupling view.
 *
 * A criterion naming a type the run never met cannot be told apart from a
 * mistyped one, while a criterion naming a type it met may still hold for a
 * class whose chain the run could not follow to the end. Only the second is a
 * reason to doubt rather than to report, which is what
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidence::reachedCounts()}
 * asks.
 *
 * Nothing is kept: one pass over the edges answers every name asked, and the
 * caller asks once per verdict, for the few names a declaration holds.
 *
 * @internal Built by {@see ClassContextFactory::knownTypes()}.
 */
final readonly class KnownTypes
{
    public function __construct(
        private ?DependencyGraphInterface $graph,
        private AnalysedDeclarations $analysed,
    ) {}

    /**
     * @param list<string> $fqns
     *
     * @return array<string, true> the subset of `$fqns` the run met
     */
    public function among(array $fqns): array
    {
        $known = [];
        $pending = [];
        foreach ($fqns as $fqn) {
            if ($this->analysed->contains($fqn) || PhpBuiltinClassHierarchy::extendsOf($fqn) !== null) {
                $known[$fqn] = true;
            } else {
                $pending[$fqn] = true;
            }
        }

        return $known + $this->onEdges($pending);
    }

    /**
     * @param array<string, true> $pending
     *
     * @return array<string, true>
     */
    private function onEdges(array $pending): array
    {
        if ($pending === [] || $this->graph === null) {
            return [];
        }

        $met = [];
        foreach ([$this->graph->getAllDependencies(), $this->graph->getDeclarationDependencies()] as $dependencies) {
            foreach ($dependencies as $dependency) {
                $met += array_intersect_key(
                    [self::fqnOf($dependency->sourceLogical()) => true, self::fqnOf($dependency->targetLogical()) => true],
                    $pending,
                );
            }
        }

        return $met;
    }

    private static function fqnOf(SymbolPath $end): string
    {
        return $end->type === null || $end->type === ''
            ? ''
            : trim(($end->namespace ?? '') . '\\' . $end->type, '\\');
    }
}
