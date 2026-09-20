<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * How deep one class declaration sits in the inheritance tree.
 *
 * Split from {@see DitGlobalCollector}, which keeps the collector protocol and
 * the repository walk: this is the part that answers the metric's question,
 * and it is the part the DIT external-ancestry campaign replaces the tail of.
 *
 * The two sides of an `extends` edge are not symmetric, and the whole shape of
 * this class follows from that. The child side carries a `DeclarationPath` —
 * exact, and the only thing that tells two declarations of one name apart. The
 * parent side carries a name, because `extends Foo` says nothing about which
 * file declared `Foo` and nothing downstream can recover it. So depth is
 * resolved per declaration, and a parent name whose declarations disagree
 * contributes the deepest of them (ADR 0073).
 */
final class InheritanceDepthResolver
{
    /** Depth of a declaration being computed; a second visit to it is a cycle. */
    private const int COMPUTING = -1;

    /** @var array<string, int> declaration canonical => resolved depth */
    private array $depths = [];

    /**
     * @param array<string, string> $parentOfDeclaration child declaration canonical => parent FQN
     * @param array<string, list<string>> $declarationsByName parent FQN => its declaration canonicals
     * @param array<string, true> $projectClasses the analysed project's own class names
     */
    private function __construct(
        private readonly array $parentOfDeclaration,
        private readonly array $declarationsByName,
        private readonly array $projectClasses,
    ) {}

    /**
     * Both views of `extends` come from the graph rather than the repository.
     *
     * The branch `declarationsByName` feeds replaces a test that asked whether
     * a name appeared among these very edge sources, so reading them keeps the
     * walk's shape for a tree without duplicates by construction rather than
     * by argument: a name declared once resolves exactly as it did before.
     *
     * @param array<string, true> $projectClasses
     */
    public static function fromGraph(DependencyGraphInterface $graph, array $projectClasses): self
    {
        $parentOfDeclaration = [];
        $declarationsByName = [];

        foreach ($graph->getAllDependencies() as $dependency) {
            if ($dependency->type !== DependencyType::Extends) {
                continue;
            }

            // An anonymous class's own `extends` is recorded with the
            // enclosing class as source (it has no declaration identity of
            // its own) — not a fact about the enclosing class's ancestry.
            if ($dependency->describesNestedAnonymousClass) {
                continue;
            }

            // Both sides of an `extends` edge name a class, and
            // `toString()` renders a class path as its FQN.
            $childFqn = $dependency->sourceLogical()->toString();
            $parentFqn = $dependency->targetLogical()->toString();

            $childDeclaration = $dependency->source->toCanonical();
            $parentOfDeclaration[$childDeclaration] = $parentFqn;

            if (!\in_array($childDeclaration, $declarationsByName[$childFqn] ?? [], true)) {
                $declarationsByName[$childFqn][] = $childDeclaration;
            }
        }

        return new self($parentOfDeclaration, $declarationsByName, $projectClasses);
    }

    /**
     * The depth of one class declaration, memoized across calls.
     */
    public function depthOf(DeclarationPath $declaration): int
    {
        return $this->depthOfCanonical($declaration->toCanonical());
    }

    /**
     * The deepest declaration carrying this name, or null when the graph
     * records no `extends` edge for it at all.
     *
     * The collector publishes one depth per name, and it cannot take that
     * maximum over the declarations it wrote: a file declaring one name twice
     * yields two edges but only one measured declaration, because every class
     * producer keys by name within a file and the second body overwrites the
     * first. Asking here instead keeps a name's published depth consistent
     * with the depth its children are given — without it, a child could report
     * a greater depth than the parent it extends.
     */
    public function deepestForName(string $classFqn): ?int
    {
        if (!isset($this->declarationsByName[$classFqn])) {
            return null;
        }

        return $this->deepestOf($this->declarationsByName[$classFqn]);
    }

    private function depthOfCanonical(string $declaration): int
    {
        if (isset($this->depths[$declaration])) {
            return $this->depths[$declaration];
        }

        $parentFqn = $this->parentOfDeclaration[$declaration] ?? null;

        // No parent in project graph
        if ($parentFqn === null) {
            return $this->depths[$declaration] = 0;
        }

        // Standard PHP class
        if (self::isStandardPhpClass($parentFqn)) {
            return $this->depths[$declaration] = 1;
        }

        $this->depths[$declaration] = self::COMPUTING;

        // The parent name is declared in the project and extends something →
        // recurse into each of its declarations.
        if (isset($this->declarationsByName[$parentFqn])) {
            return $this->depths[$declaration] = 1 + $this->deepestOf($this->declarationsByName[$parentFqn]);
        }

        // In the project and carrying no parent of its own: a root, and none of
        // this tool's business to go looking for it elsewhere.
        if (isset($this->projectClasses[$parentFqn])) {
            return $this->depths[$declaration] = 1;
        }

        // Genuinely outside the analysed path.
        return $this->depths[$declaration] = 1 + self::resolveExternalClassDit($parentFqn);
    }

    /**
     * The depth of the deepest declaration carrying one name.
     *
     * Zero when every one of them is still being computed, which is a cycle: a
     * branch in progress says nothing about depth, so it takes no part in the
     * maximum, and a name made only of such branches leaves its child at the
     * depth the cycle has always reported.
     *
     * @param list<string> $declarations
     */
    private function deepestOf(array $declarations): int
    {
        $depths = [];

        foreach ($declarations as $declaration) {
            $depth = $this->depthOfCanonical($declaration);

            if ($depth !== self::COMPUTING) {
                $depths[] = $depth;
            }
        }

        return $depths === [] ? 0 : max($depths);
    }

    private static function isStandardPhpClass(string $fqn): bool
    {
        return PhpBuiltinClassRegistry::isBuiltin(ltrim($fqn, '\\'));
    }

    /**
     * Try to resolve DIT for an external class via reflection.
     *
     * The autoloader consulted here belongs to this tool, not to the analysed
     * project, so an analysed FQCN can map onto the tool's own vendored copy.
     * When that copy is reachable but names a parent the tool's install never
     * ships, loading it throws instead of returning false. Nothing guards this
     * resolver -- aggregation calls it directly -- so an escaping error ends
     * the whole run rather than one file's metric.
     *
     * @return int DIT of the external class, or 0 if cannot resolve
     */
    private static function resolveExternalClassDit(string $classFqn): int
    {
        $normalized = ltrim($classFqn, '\\');

        // Widest catch on the load step alone: it runs someone else's code and
        // fails with a plain Error carrying nothing to match on. The walk below
        // cannot autoload, so a throw there is this tool's own defect and keeps
        // a narrow catch, staying loud.
        try {
            if (!class_exists($normalized, true) && !interface_exists($normalized, true)) {
                return 0;
            }
        } catch (Throwable) {
            return 0;
        }

        try {
            $depth = 0;
            $current = new ReflectionClass($normalized);

            while (($parent = $current->getParentClass()) !== false) {
                ++$depth;

                if (self::isStandardPhpClass($parent->getName())) {
                    break;
                }

                $current = $parent;
            }

            return $depth;
        } catch (ReflectionException) {
            return 0;
        }
    }
}
