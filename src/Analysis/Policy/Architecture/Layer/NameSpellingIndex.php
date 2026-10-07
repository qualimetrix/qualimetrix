<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Canonical observed spelling for each PHP class-name identity in one run. */
final readonly class NameSpellingIndex
{
    /** @var array<string, string> folded identity => spelling */
    private array $spellings;

    /** @param iterable<SymbolPath> $analysedClasses */
    public function __construct(DependencyGraphInterface $graph, iterable $analysedClasses)
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

        $spellings = [];
        foreach ($byIdentity as $identity => $forms) {
            $spellings[$identity] = ClassNameSpelling::canonical(array_keys($forms));
        }
        $this->spellings = $spellings;
    }

    public function spellingOf(string $name): ?string
    {
        return $this->spellings[ClassNameSpelling::fold(ltrim($name, '\\'))] ?? null;
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
