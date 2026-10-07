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

    /** @var array<string, true> */
    private array $externalRelationsLoaded = [];

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
        $declaredInterfaces = $this->relations->implementsOf($fqn);
        $seeds = $declaredInterfaces !== []
            ? $declaredInterfaces
            : PhpBuiltinClassHierarchy::interfacesOf($fqn) ?? [];
        if ($this->relations->isInterface($fqn)) {
            foreach ($this->relations->extendsOf($fqn) ?? [] as $parent) {
                $seeds[] = $parent;
            }
        } else {
            foreach ($parents as $parent) {
                $declaredInterfaces = $this->relations->implementsOf($parent);
                $parentInterfaces = $declaredInterfaces !== []
                    ? $declaredInterfaces
                    : PhpBuiltinClassHierarchy::interfacesOf($parent) ?? [];
                foreach ($parentInterfaces as $interface) {
                    $seeds[] = $interface;
                }
            }
        }

        return $this->closure(
            $seeds,
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
        $this->loadExternalDeclaration($fqn);
        $facts = $this->externalFacts[$fqn] ?? null;

        return $facts?->declaredSpelling === null ? null : $facts;
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
        $discovered = [];
        $queue = [];
        foreach ($seeds as $seed) {
            if (!isset($discovered[$seed])) {
                $discovered[$seed] = true;
                $queue[] = [$seed, 1];
            }
        }

        for ($cursor = 0; isset($queue[$cursor]); ++$cursor) {
            [$next, $depth] = $queue[$cursor];
            $result[] = $next;
            $neighbours = $this->supertypesOf($next, $unresolved, $phpAbove, $alsoAbove);
            if ($depth >= self::MAX_DEPTH) {
                foreach ($neighbours as $neighbour) {
                    if (!isset($discovered[$neighbour])) {
                        $unresolved[$neighbour] = true;
                    }
                }

                continue;
            }
            foreach ($neighbours as $neighbour) {
                if (!isset($discovered[$neighbour])) {
                    $discovered[$neighbour] = true;
                    $queue[] = [$neighbour, $depth + 1];
                }
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
            $this->loadExternalDeclaration($fqn);
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

    private function loadExternalDeclaration(string $fqn): void
    {
        if (isset($this->externalRelationsLoaded[$fqn])) {
            return;
        }
        $source = $this->externalSource;
        if ($source === null || !$source->isConfigured()) {
            return;
        }
        $facts = $this->externalFactsOf($fqn);
        $this->externalRelationsLoaded[$fqn] = true;
        if ($facts->declaredSpelling !== null && $facts->classType !== null) {
            $this->relations->addExternal($fqn, $facts);
        }
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

        return $this->externalFacts[$fqn] = $source === null || !$source->isConfigured()
            ? ExternalSupertypes::notPlaced()
            : $source->supertypesOf($fqn);
    }
}
