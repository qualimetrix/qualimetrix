<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Canonical observed spelling for each PHP class-name identity in one run. */
final readonly class NameSpellingIndex
{
    /** @var array<string, string> folded identity => spelling */
    private array $spellings;

    /** @var array<string, string> folded namespace/class prefix => spelling */
    private array $prefixSpellings;

    /** @param iterable<SymbolPath> $analysedClasses */
    public function __construct(DependencyGraphInterface $graph, iterable $analysedClasses)
    {
        $spellings = self::canonicalSpellings(self::collectSpellings($graph, $analysedClasses));
        $this->spellings = $spellings;
        $this->prefixSpellings = self::canonicalSpellings(self::collectPrefixes($spellings));
    }

    /**
     * @param iterable<SymbolPath> $analysedClasses
     *
     * @return array<string, array<string, true>>
     */
    private static function collectSpellings(DependencyGraphInterface $graph, iterable $analysedClasses): array
    {
        $byIdentity = [];
        foreach ($analysedClasses as $class) {
            self::add($byIdentity, self::fqn($class));
        }
        foreach ($graph->getClassLikeDeclarations() as $declaration) {
            self::add($byIdentity, self::fqn($declaration->logical->symbolPath));
        }
        foreach ($graph->getAllClasses() as $class) {
            self::add($byIdentity, self::fqn($class));
        }
        foreach ([$graph->getAllDependencies(), $graph->getDeclarationDependencies()] as $dependencies) {
            foreach ($dependencies as $dependency) {
                self::add($byIdentity, self::fqn($dependency->sourceLogical()));
                self::add($byIdentity, self::fqn($dependency->targetLogical()));
            }
        }

        return $byIdentity;
    }

    /**
     * @param array<string, string> $spellings
     *
     * @return array<string, array<string, true>>
     */
    private static function collectPrefixes(array $spellings): array
    {
        $prefixes = [];
        foreach ($spellings as $spelling) {
            $segments = explode('\\', $spelling);
            $segmentCount = \count($segments);
            for ($length = 1; $length <= $segmentCount; ++$length) {
                self::add($prefixes, implode('\\', \array_slice($segments, 0, $length)));
            }
        }

        return $prefixes;
    }

    /**
     * @param array<string, array<string, true>> $formsByIdentity
     *
     * @return array<string, string>
     */
    private static function canonicalSpellings(array $formsByIdentity): array
    {
        $spellings = [];
        foreach ($formsByIdentity as $identity => $forms) {
            $spellings[$identity] = ClassNameSpelling::canonical(array_keys($forms));
        }

        return $spellings;
    }

    public function spellingOf(string $name): ?string
    {
        return $this->spellings[ClassNameSpelling::fold(ltrim($name, '\\'))] ?? null;
    }

    public function prefixSpellingOf(string $prefix): ?string
    {
        return $this->prefixSpellings[ClassNameSpelling::fold(ltrim($prefix, '\\'))] ?? null;
    }

    /** @param array<string, array<string, true>> $index */
    private static function add(array &$index, ?string $spelling): void
    {
        if ($spelling === null) {
            return;
        }
        $index[ClassNameSpelling::fold($spelling)][$spelling] = true;
    }

    private static function fqn(SymbolPath $path): ?string
    {
        if ($path->type === null || $path->type === '') {
            return null;
        }

        return $path->namespace === null || $path->namespace === ''
            ? $path->type
            : $path->namespace . '\\' . $path->type;
    }
}
