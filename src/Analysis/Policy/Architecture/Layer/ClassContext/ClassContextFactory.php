<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;

use Closure;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypeSourceInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\AnalysedDeclarations;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds class membership facts from one bound dependency graph.
 *
 * Declaration indexing, bounded ancestry and implicit Stringable inference are
 * separate parts of this subject. The factory owns their shared run lifecycle:
 * rebinding either input rebuilds all derived facts and clears context memoization.
 * Each ancestry branch includes at most 256 links; a relation beyond that limit
 * is carried as an unresolved cut rather than a complete negative answer.
 */
final class ClassContextFactory
{
    private ?DependencyGraphInterface $graph = null;

    private ?NameSpellingIndex $nameSpellings = null;

    private ?DeclarationRelations $relations = null;

    private ?Ancestry $ancestry = null;

    private ?ImplicitStringability $implicitStringability = null;

    /** @var array<string, ClassContext> */
    private array $contextCache = [];

    private AnalysedDeclarations $analysed;

    /** @var (Closure(string): bool)|null */
    private ?Closure $installDeclares = null;

    private ?ExternalSupertypeSourceInterface $externalSupertypes = null;

    public function __construct()
    {
        $this->analysed = AnalysedDeclarations::unknown();
    }

    public function bindExternalSupertypeSource(?ExternalSupertypeSourceInterface $source): void
    {
        $this->externalSupertypes = $source;
        $this->rebuildDerivedFacts();
    }

    /**
     * @param iterable<SymbolPath>|null $analysedClasses
     * @param (Closure(string): bool)|null $installDeclares
     */
    public function bindGraph(
        ?DependencyGraphInterface $graph,
        ?iterable $analysedClasses = null,
        ?Closure $installDeclares = null,
    ): void {
        $analysedList = $analysedClasses === null
            ? null
            : (\is_array($analysedClasses) ? array_values($analysedClasses) : iterator_to_array($analysedClasses, false));

        $this->graph = $graph;
        $this->installDeclares = $installDeclares;
        $this->analysed = $analysedList === null
            ? AnalysedDeclarations::unknown()
            : AnalysedDeclarations::of($analysedList);
        $this->nameSpellings = $graph === null ? null : new NameSpellingIndex($graph, $analysedList ?? []);
        $this->rebuildDerivedFacts();
    }

    public function build(SymbolPath $class): ClassContext
    {
        $fqn = self::fqnFor($class);
        if ($fqn === null) {
            return new ClassContext('', '');
        }

        $cacheKey = $class->toCanonical();
        if (isset($this->contextCache[$cacheKey])) {
            return $this->contextCache[$cacheKey];
        }
        if ($this->graph === null) {
            return $this->contextCache[$cacheKey] = new ClassContext(
                $fqn,
                self::deriveShortName($fqn),
                graphBacked: false,
            );
        }
        if ($class->type === null || $class->type === '') {
            return $this->contextCache[$cacheKey] = new ClassContext($fqn, self::deriveShortName($fqn));
        }

        $relations = $this->relations;
        $ancestry = $this->ancestry;
        $stringability = $this->implicitStringability;
        \assert($relations !== null && $ancestry !== null && $stringability !== null);

        $fqn = $relations->spellingOf($fqn);
        $stringability->apply($fqn);
        $parentCuts = [];
        $interfaceCuts = [];
        $parents = $ancestry->parentsOf($fqn, $parentCuts);
        $interfaces = $ancestry->interfacesOf($fqn, $parents, $interfaceCuts);
        $attributes = $relations->attributesOf($fqn)
            ?? PhpBuiltinClassHierarchy::attributesOf($fqn)
            ?? [];

        return $this->contextCache[$cacheKey] = new ClassContext(
            $fqn,
            self::deriveShortName($fqn),
            $attributes,
            $interfaces,
            $parents,
            ancestryCuts: ['parentChain' => array_keys($parentCuts), 'interfaces' => array_keys($interfaceCuts)],
            memberAttributeFqns: $relations->memberAttributesOf($fqn),
            implicitStringableKnown: $stringability->knownFor([$fqn, ...$parents, ...$interfaces]),
            declarationAnalysed: $ancestry->hasOwnDeclarationFacts($fqn),
        );
    }

    public function knownTypes(): KnownTypes
    {
        $source = $this->externalSupertypes;
        $ancestry = $this->ancestry;
        $installDeclares = $this->installDeclares;
        $declaredSpelling = null;
        if ($source !== null && $source->isConfigured()) {
            $installDeclares = $ancestry === null
                ? static fn(string $fqn): bool => self::sourceDeclares($source, $fqn)
                : $ancestry->externalTypeExists(...);
            $declaredSpelling = $ancestry === null
                ? static fn(string $fqn): ?string => self::sourceSpelling($source, $fqn)
                : $ancestry->externalDeclaredSpelling(...);
        }

        return new KnownTypes(
            $this->graph,
            $this->analysed,
            $installDeclares,
            $this->nameSpellings,
            $declaredSpelling,
        );
    }

    private function rebuildDerivedFacts(): void
    {
        $this->contextCache = [];
        $graph = $this->graph;
        $spellings = $this->nameSpellings;
        if ($graph === null || $spellings === null) {
            $this->relations = null;
            $this->ancestry = null;
            $this->implicitStringability = null;

            return;
        }

        $relations = new DeclarationRelations($graph, $spellings);
        $ancestry = new Ancestry($relations, $this->analysed, $this->externalSupertypes);
        $stringability = new ImplicitStringability($relations, $ancestry);
        $stringability->applyToDeclarations();
        $this->relations = $relations;
        $this->ancestry = $ancestry;
        $this->implicitStringability = $stringability;
    }

    private static function sourceDeclares(ExternalSupertypeSourceInterface $source, string $fqn): bool
    {
        $facts = $source->supertypesOf($fqn);

        return $facts->placed && $facts->declaredSpelling === ltrim($fqn, '\\');
    }

    private static function sourceSpelling(ExternalSupertypeSourceInterface $source, string $fqn): ?string
    {
        $facts = $source->supertypesOf($fqn);

        return $facts->placed ? $facts->declaredSpelling : null;
    }

    private static function fqnFor(SymbolPath $class): ?string
    {
        $fqn = trim(($class->namespace ?? '') . '\\' . ($class->type ?? ''), '\\');

        return $fqn === '' ? null : $fqn;
    }

    private static function deriveShortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}
