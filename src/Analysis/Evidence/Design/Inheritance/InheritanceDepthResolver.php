<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use LogicException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;

/** Resolves exact declarations and every possible declaration of a named parent. */
final class InheritanceDepthResolver
{
    /** @var array<string, InheritanceResolution> */
    private array $completed = [];
    /** @var array<string, true> canonical PHP identities on the current path */
    private array $active = [];

    /**
     * @param array<string, string> $names exact declaration => folded name
     * @param array<string, non-empty-list<string>> $declarationsByName
     * @param array<string, string> $parents exact declaration => parent name
     * @param array<string, true> $throwableDeclarations
     */
    private function __construct(
        private readonly array $names,
        private readonly array $declarationsByName,
        private readonly array $parents,
        private readonly array $throwableDeclarations,
        private readonly ExternalAncestry $externalAncestry,
    ) {}

    public static function fromGraph(DependencyGraphInterface $graph, ExternalAncestry $externalAncestry): self
    {
        [$names, $declarationsByName] = self::classRoster($graph);
        [$parents, $throwableDeclarations] = self::declarationRelations($graph, $names);

        return new self($names, $declarationsByName, $parents, $throwableDeclarations, $externalAncestry);
    }

    /** @return array{array<string, string>, array<string, non-empty-list<string>>} */
    private static function classRoster(DependencyGraphInterface $graph): array
    {
        $names = [];
        $declarationsByName = [];
        foreach ($graph->getClassLikeDeclarations() as $fact) {
            if ($fact->type !== ClassType::Class_) {
                continue;
            }
            $exact = $fact->declaration->toCanonical();
            $name = ClassNameSpelling::fold($fact->logical->symbolPath->toString());
            $names[$exact] = $name;
            $declarationsByName[$name][] = $exact;
        }

        return [$names, $declarationsByName];
    }

    /**
     * @param array<string, string> $names
     *
     * @return array{array<string, string>, array<string, true>}
     */
    private static function declarationRelations(DependencyGraphInterface $graph, array $names): array
    {
        $parents = [];
        $throwableDeclarations = [];
        foreach ($graph->getDeclarationDependencies() as $dependency) {
            $exact = $dependency->source->toCanonical();
            if (!isset($names[$exact]) || $dependency->describesNestedAnonymousClass || $dependency->interfaceExtends) {
                continue;
            }
            $target = ltrim($dependency->targetLogical()->toString(), '\\');
            if ($dependency->type === DependencyType::Extends) {
                $parents[$exact] = $target;
            } elseif ($dependency->type === DependencyType::Implements && self::interfaceReachesThrowable($target)) {
                $throwableDeclarations[$exact] = true;
            }
        }

        return [$parents, $throwableDeclarations];
    }

    private static function interfaceReachesThrowable(string $target): bool
    {
        return ClassNameSpelling::fold($target) === 'throwable'
            || \in_array('Throwable', PhpBuiltinClassHierarchy::interfacesOf($target) ?? [], true);
    }

    public function depthOf(DeclarationPath $declaration): InheritanceResolution
    {
        return $this->resolve($declaration->toCanonical());
    }

    private function resolve(string $exact): InheritanceResolution
    {
        $name = $this->names[$exact] ?? throw new LogicException('Inheritance resolution requires a graph class declaration');
        // A memo belongs to an exact body, but a parent names a PHP identity.
        // Check the path first: a completed alternative cannot erase a cycle.
        if (isset($this->active[$name])) {
            return new InheritanceResolution(null, InheritanceOutcome::Loop, isset($this->throwableDeclarations[$exact]) ? true : null);
        }
        if (isset($this->completed[$exact])) {
            return $this->completed[$exact];
        }
        $this->active[$name] = true;
        try {
            $answer = $this->declarationResolution($exact);
        } finally {
            unset($this->active[$name]);
        }

        return $this->completed[$exact] = $answer;
    }

    private function declarationResolution(string $exact): InheritanceResolution
    {
        $parent = $this->parents[$exact] ?? null;
        if ($parent === null) {
            return new InheritanceResolution(0, InheritanceOutcome::Exact, isset($this->throwableDeclarations[$exact]));
        }
        $answer = $this->parentResolution($parent);

        return new InheritanceResolution(
            $answer->depth === null ? null : 1 + $answer->depth,
            $answer->outcome,
            isset($this->throwableDeclarations[$exact]) ? true : $answer->reachesThrowable,
        );
    }

    private function parentResolution(string $parent): InheritanceResolution
    {
        $declarations = $this->declarationsByName[ClassNameSpelling::fold($parent)] ?? null;
        if ($declarations === null) {
            return $this->externalResolution($parent);
        }

        return $this->mergeParentAnswers(array_map($this->resolve(...), $declarations));
    }

    private function externalResolution(string $parent): InheritanceResolution
    {
        $tail = $this->externalAncestry->depthOf($parent);

        return new InheritanceResolution($tail->depth, match ($tail->outcome) {
            ExternalChainOutcome::ReachedRoot => InheritanceOutcome::Exact,
            ExternalChainOutcome::Loop => InheritanceOutcome::Loop,
            default => InheritanceOutcome::Floor,
        }, $tail->reachesThrowable);
    }

    /** @param non-empty-list<InheritanceResolution> $answers */
    private function mergeParentAnswers(array $answers): InheritanceResolution
    {
        $outcome = InheritanceOutcome::Exact;
        $depth = 0;
        $truth = $answers[0]->reachesThrowable;
        foreach ($answers as $answer) {
            $outcome = self::dominantOutcome($outcome, $answer->outcome);
            $depth = max($depth, $answer->depth ?? 0);
            if ($truth !== $answer->reachesThrowable) {
                $truth = null;
            }
        }

        return new InheritanceResolution($outcome === InheritanceOutcome::Loop ? null : $depth, $outcome, $truth);
    }

    private static function dominantOutcome(InheritanceOutcome $first, InheritanceOutcome $second): InheritanceOutcome
    {
        $outcomes = [$first, $second];
        if (\in_array(InheritanceOutcome::Loop, $outcomes, true)) {
            return InheritanceOutcome::Loop;
        }
        if (\in_array(InheritanceOutcome::Floor, $outcomes, true)) {
            return InheritanceOutcome::Floor;
        }

        return InheritanceOutcome::Exact;
    }
}
