<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypes;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypeSourceInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\AnalysedDeclarations;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;

/** Resolves bounded parent and interface ancestry for class contexts. */
final class Ancestry
{
    public const int MAX_DEPTH = 256;

    /** @var array<string, ExternalSupertypes> */
    private array $externalFacts = [];

    public function __construct(
        private readonly DeclarationRelations $relations,
        private readonly AnalysedDeclarations $analysed,
        private readonly ?ExternalSupertypeSourceInterface $externalSource,
    ) {}

    /**
     * @param array<string, true> $unresolved
     *
     * @return list<string>
     */
    public function parentsOf(string $fqn, array &$unresolved): array
    {
        $phpParents = PhpBuiltinClassHierarchy::extendsOf(...);

        return $this->closure($this->supertypesOf($fqn, $unresolved, $phpParents), $unresolved, $phpParents);
    }

    /**
     * @param list<string> $parents
     * @param array<string, true> $unresolved
     *
     * @return list<string>
     */
    public function interfacesOf(string $fqn, array $parents, array &$unresolved): array
    {
        $seeds = $this->directInterfacesOf($fqn);
        $inherited = $this->relations->isInterface($fqn)
            ? $this->relations->extendsOf($fqn) ?? []
            : array_merge([], ...array_map($this->directInterfacesOf(...), $parents));

        return $this->closure(
            [...$seeds, ...$inherited],
            $unresolved,
            PhpBuiltinClassHierarchy::interfacesOf(...),
            $this->relations->implementsMap(),
        );
    }

    public function declarationFacts(string $fqn): ClassLikeDeclaration|ExternalSupertypes|null
    {
        $declaration = $this->relations->declarationOf($fqn);
        if ($declaration !== null) {
            return $declaration;
        }
        $facts = $this->externalFactsOf($fqn);

        return $facts->declaredSpelling === null ? null : $facts;
    }

    public function classTypeOf(string $fqn): ?ClassType
    {
        $facts = $this->declarationFacts($fqn);

        return $facts instanceof ClassLikeDeclaration ? $facts->type : $facts?->classType;
    }

    public function hasOwnDeclarationFacts(string $fqn): bool
    {
        if ($this->relations->declarationOf($fqn) !== null || PhpBuiltinClassRegistry::canonicalName($fqn) !== null) {
            return true;
        }
        if (($this->externalFacts[$fqn]->declaredSpelling ?? null) !== null) {
            return false;
        }

        return $this->analysed->contains($fqn);
    }

    public function externalTypeExists(string $fqn): bool
    {
        $facts = $this->externalFactsOf($fqn);

        return $facts->placed && $facts->declaredSpelling === ltrim($fqn, '\\');
    }

    public function externalDeclaredSpelling(string $fqn): ?string
    {
        $identity = ClassNameSpelling::fold($fqn);
        foreach ($this->externalFacts as $facts) {
            if ($facts->declaredSpelling !== null && ClassNameSpelling::fold($facts->declaredSpelling) === $identity) {
                return $facts->declaredSpelling;
            }
        }
        $facts = $this->externalFactsOf($fqn);

        return $facts->placed ? $facts->declaredSpelling : null;
    }

    /**
     * @param list<string> $seeds
     * @param array<string, true> $unresolved
     * @param callable(string): (list<string>|null) $phpAbove
     * @param array<string, list<string>> $alsoAbove
     *
     * @return list<string>
     */
    private function closure(array $seeds, array &$unresolved, callable $phpAbove, array $alsoAbove = []): array
    {
        $result = [];
        $seeds = array_values(array_unique($seeds));
        $discovered = array_fill_keys($seeds, true);
        $queue = array_map(static fn(string $seed): array => [$seed, 1], $seeds);

        for ($cursor = 0; isset($queue[$cursor]); ++$cursor) {
            [$next, $depth] = $queue[$cursor];
            $result[] = $next;
            $neighbours = $this->supertypesOf($next, $unresolved, $phpAbove, $alsoAbove);
            $fresh = array_values(array_unique(array_filter(
                $neighbours,
                static fn(string $neighbour): bool => !isset($discovered[$neighbour]),
            )));
            if ($depth >= self::MAX_DEPTH) {
                $unresolved += array_fill_keys($fresh, true);

                continue;
            }
            $discovered += array_fill_keys($fresh, true);
            foreach ($fresh as $neighbour) {
                $queue[] = [$neighbour, $depth + 1];
            }
        }

        return $result;
    }

    /**
     * @param array<string, true> $unresolved
     * @param callable(string): (list<string>|null) $phpAbove
     * @param array<string, list<string>> $alsoAbove
     *
     * @return list<string>
     */
    private function supertypesOf(string $fqn, array &$unresolved, callable $phpAbove, array $alsoAbove = []): array
    {
        $phpRelations = $phpAbove($fqn);
        if (!$this->analysed->contains($fqn) && $phpRelations === null) {
            $this->externalFactsOf($fqn);
        }

        $also = $alsoAbove[$fqn] ?? [];
        $above = $this->relations->extendsOf($fqn) ?? $phpRelations;
        if ($above !== null) {
            return [...$above, ...$also];
        }
        if ($also !== []) {
            return $also;
        }
        if (!$this->hasReadableDeclaration($fqn)) {
            $unresolved[$fqn] = true;
        }

        return [];
    }

    /** @return list<string> */
    private function directInterfacesOf(string $fqn): array
    {
        $declared = $this->relations->implementsOf($fqn);

        return $declared !== [] ? $declared : PhpBuiltinClassHierarchy::interfacesOf($fqn) ?? [];
    }

    private function hasReadableDeclaration(string $fqn): bool
    {
        return $this->analysed->contains($fqn)
            || ($this->externalFacts[$fqn]->declaredSpelling ?? null) !== null;
    }

    private function externalFactsOf(string $fqn): ExternalSupertypes
    {
        if (isset($this->externalFacts[$fqn])) {
            return $this->externalFacts[$fqn];
        }
        $source = $this->externalSource;

        $facts = $source === null || !$source->isConfigured()
            ? ExternalSupertypes::notPlaced()
            : $source->supertypesOf($fqn);
        $this->externalFacts[$fqn] = $facts;
        if ($facts->declaredSpelling !== null && $facts->classType !== null) {
            $this->relations->addExternal($fqn, $facts);
        }

        return $facts;
    }
}
