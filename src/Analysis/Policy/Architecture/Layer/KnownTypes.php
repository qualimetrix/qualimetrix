<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Closure;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The types one run met at all: a declaration it analysed, a class or
 * interface PHP declares, a name at either end of a dependency edge in the
 * declaration or the coupling view, or a class or interface the analysed
 * project's own composer install declares.
 *
 * A criterion naming a type the run never met cannot be told apart from a
 * mistyped one, while a criterion naming a type it met may still hold for a
 * class whose chain the run could not follow to the end. Only the second is a
 * reason to doubt rather than to report, which is what
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidence::reachedCounts()}
 * asks.
 *
 * The install is the last source asked, because it reads files: a type only
 * a vendor chain reaches — an interface a vendor base class implements — is
 * named by no analysed code and sits at the end of no edge, and without it
 * every such criterion read as a typo. It is read, never loaded, through the
 * port DIT's ancestor walk reads it by; a name it cannot place — nothing maps
 * it, or the mapped file declares another name, a different case included —
 * stays unmet. A run with no install to read asks nothing, and
 * {@see installConsulted()} says so to the finding that names unmet types.
 *
 * Nothing is kept here: one pass over the edges answers every name asked, the
 * reader behind the port caches each file it opens, and the caller asks once
 * per verdict, for the few names a declaration holds.
 *
 * @internal Built by {@see ClassContextFactory::knownTypes()}.
 */
final readonly class KnownTypes
{
    /**
     * @param (Closure(string): bool)|null $installDeclares Whether the analysed project's composer
     *                                                      install declares a class or interface by
     *                                                      this exact name; null when the run found
     *                                                      no install to read.
     */
    public function __construct(
        private ?DependencyGraphInterface $graph,
        private AnalysedDeclarations $analysed,
        private ?Closure $installDeclares = null,
    ) {}

    public function installConsulted(): bool
    {
        return $this->installDeclares !== null;
    }

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

        $known += $this->onEdges($pending);

        return $known + $this->inInstall(array_diff_key($pending, $known));
    }

    /**
     * @param array<string, true> $pending
     *
     * @return array<string, true>
     */
    private function inInstall(array $pending): array
    {
        $declares = $this->installDeclares;
        if ($declares === null) {
            return [];
        }

        return array_filter($pending, static fn(string $fqn): bool => $declares($fqn), \ARRAY_FILTER_USE_KEY);
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
