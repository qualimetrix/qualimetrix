<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds a class-level dependency graph from a plain adjacency list.
 *
 * Insertion order is preserved verbatim — both the order in which classes first
 * appear and the order of each node's outgoing edges — which is what makes the
 * builder usable for order-sensitivity tests such as
 * {@see \Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit\CycleIdentityStabilityTest}.
 *
 * Only the fields the graph algorithms read are populated; the coupling counters
 * are left empty.
 */
final readonly class AdjacencyGraphBuilder
{
    /**
     * @param array<string, list<string>> $adjacencyList Class FQN → the FQNs it depends on
     */
    public static function build(array $adjacencyList): DependencyGraphInterface
    {
        $dependencies = [];
        $bySource = [];
        $byTarget = [];
        /** @var array<string, SymbolPath> $classMap */
        $classMap = [];

        foreach ($adjacencyList as $source => $targets) {
            $sourcePath = SymbolPath::fromClassFqn($source);
            $sourceKey = $sourcePath->toCanonical();
            $classMap[$sourceKey] = $sourcePath;

            foreach ($targets as $target) {
                $targetPath = SymbolPath::fromClassFqn($target);
                $targetKey = $targetPath->toCanonical();
                $classMap[$targetKey] = $targetPath;

                $dependency = new Dependency(
                    source: new DeclarationPath($sourcePath, RelativePath::fromString('test.php'), 0),
                    target: new LogicalClassPath($targetPath),
                    type: DependencyType::TypeHint,
                    location: new Location(RelativePath::fromString('test.php'), 1),
                );

                $dependencies[] = $dependency;
                $bySource[$sourceKey][] = $dependency;
                $byTarget[$targetKey][] = $dependency;
            }
        }

        return self::fromState($dependencies, $bySource, $byTarget, array_values($classMap));
    }

    public static function empty(): DependencyGraphInterface
    {
        return self::fromState([], [], [], []);
    }

    public static function builder(): DependencyGraphBuilderInterface
    {
        return new TestDependencyGraphBuilder();
    }

    /**
     * @param list<Dependency> $dependencies
     * @param array<string, list<Dependency>> $bySource
     * @param array<string, list<Dependency>> $byTarget
     * @param list<SymbolPath> $classes
     */
    public static function fromState(
        array $dependencies,
        array $bySource,
        array $byTarget,
        array $classes,
    ): DependencyGraphInterface {
        $namespaces = [];
        foreach ($classes as $class) {
            if ($class->namespace !== null) {
                $namespaces[$class->namespace] ??= SymbolPath::forNamespace($class->namespace);
            }
        }

        $classCe = array_map(static fn(array $entries): int => \count(array_unique(array_map(
            static fn(Dependency $dependency): string => $dependency->targetLogical()->toCanonical(),
            $entries,
        ))), $bySource);
        $classCa = array_map(static fn(array $entries): int => \count(array_unique(array_map(
            static fn(Dependency $dependency): string => $dependency->sourceLogical()->toCanonical(),
            $entries,
        ))), $byTarget);
        $namespaceCe = [];
        $namespaceCa = [];
        foreach ($dependencies as $dependency) {
            $sourceNamespace = $dependency->sourceLogical()->namespace;
            $targetNamespace = $dependency->targetLogical()->namespace;
            if ($sourceNamespace === null || $targetNamespace === null || $sourceNamespace === $targetNamespace) {
                continue;
            }
            $sourceKey = SymbolPath::forNamespace($sourceNamespace)->toCanonical();
            $targetKey = SymbolPath::forNamespace($targetNamespace)->toCanonical();
            $namespaceCe[$sourceKey][$dependency->targetLogical()->toCanonical()] = true;
            $namespaceCa[$targetKey][$dependency->sourceLogical()->toCanonical()] = true;
        }

        return new class ($dependencies, $bySource, $byTarget, $classes, array_values($namespaces), $classCe, $classCa, $namespaceCe, $namespaceCa) implements DependencyGraphInterface {
            /** @phpstan-var list<Dependency> */
            private readonly array $dependencies;

            /** @phpstan-var array<string, list<Dependency>> */
            private readonly array $bySource;

            /** @phpstan-var array<string, list<Dependency>> */
            private readonly array $byTarget;

            /** @phpstan-var list<SymbolPath> */
            private readonly array $classes;

            /** @phpstan-var list<SymbolPath> */
            private readonly array $namespaces;

            /** @phpstan-var array<string, int> */
            private readonly array $classCe;

            /** @phpstan-var array<string, int> */
            private readonly array $classCa;

            /** @phpstan-var array<string, array<string, true>> */
            private readonly array $namespaceCe;

            /** @phpstan-var array<string, array<string, true>> */
            private readonly array $namespaceCa;

            /**
             * @phpstan-param list<Dependency> $dependencies
             * @phpstan-param array<string, list<Dependency>> $bySource
             * @phpstan-param array<string, list<Dependency>> $byTarget
             * @phpstan-param list<SymbolPath> $classes
             * @phpstan-param list<SymbolPath> $namespaces
             * @phpstan-param array<string, int> $classCe
             * @phpstan-param array<string, int> $classCa
             * @phpstan-param array<string, array<string, true>> $namespaceCe
             * @phpstan-param array<string, array<string, true>> $namespaceCa
             */
            public function __construct(
                array $dependencies,
                array $bySource,
                array $byTarget,
                array $classes,
                array $namespaces,
                array $classCe,
                array $classCa,
                array $namespaceCe,
                array $namespaceCa,
            ) {
                $this->dependencies = $dependencies;
                $this->bySource = $bySource;
                $this->byTarget = $byTarget;
                $this->classes = $classes;
                $this->namespaces = $namespaces;
                $this->classCe = $classCe;
                $this->classCa = $classCa;
                $this->namespaceCe = $namespaceCe;
                $this->namespaceCa = $namespaceCa;
            }

            public function getClassDependencies(SymbolPath $class): array
            {
                return $this->bySource[$class->toCanonical()] ?? [];
            }
            public function getClassDependents(SymbolPath $class): array
            {
                return $this->byTarget[$class->toCanonical()] ?? [];
            }
            public function getClassCe(SymbolPath $class): int
            {
                return $this->classCe[$class->toCanonical()] ?? 0;
            }
            public function getClassCa(SymbolPath $class): int
            {
                return $this->classCa[$class->toCanonical()] ?? 0;
            }
            public function getNamespaceCe(SymbolPath $namespace): int
            {
                return \count($this->namespaceCe[$namespace->toCanonical()] ?? []);
            }
            public function getNamespaceCa(SymbolPath $namespace): int
            {
                return \count($this->namespaceCa[$namespace->toCanonical()] ?? []);
            }
            public function getAllClasses(): array
            {
                return $this->classes;
            }
            public function getAllNamespaces(): array
            {
                return $this->namespaces;
            }
            public function getAllDependencies(): array
            {
                return $this->dependencies;
            }
        };
    }
}

final readonly class TestDependencyGraphBuilder implements DependencyGraphBuilderInterface
{
    public function build(array $dependencies, iterable $logicalClassUniverse): DependencyGraphInterface
    {
        $classes = [];
        foreach ($logicalClassUniverse as $logicalClass) {
            $classes[$logicalClass->symbolPath->toCanonical()] = $logicalClass->symbolPath;
        }
        $bySource = [];
        $byTarget = [];
        foreach ($dependencies as $dependency) {
            $source = $dependency->sourceLogical();
            $target = $dependency->targetLogical();
            $classes[$source->toCanonical()] = $source;
            $classes[$target->toCanonical()] = $target;
            $bySource[$source->toCanonical()][] = $dependency;
            $byTarget[$target->toCanonical()][] = $dependency;
        }

        return AdjacencyGraphBuilder::fromState($dependencies, $bySource, $byTarget, array_values($classes));
    }
}
