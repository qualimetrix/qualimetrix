<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\GlobalContextCollectorInterface;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use ReflectionClass;
use ReflectionException;

/**
 * Recalculates DIT (Depth of Inheritance Tree) using the global dependency graph.
 *
 * The per-file InheritanceDepthCollector can only see classes within a single file,
 * so it cannot traverse inheritance chains that span multiple files. This global
 * collector builds a complete parent map from the dependency graph and recalculates
 * DIT correctly for all project classes.
 *
 * For classes whose parents are outside the project (not in the dependency graph),
 * PHP reflection is used as a fallback to traverse the external chain.
 */
final class DitGlobalCollector implements GlobalContextCollectorInterface
{
    private const NAME = 'dit-global';

    /**
     * Standard PHP classes considered root (DIT stops here).
     * Kept in sync with InheritanceDepthCollector::STANDARD_PHP_CLASSES.
     *
     * @var array<string, true>
     */
    private const STANDARD_PHP_CLASSES = [
        'stdClass' => true,
        'Exception' => true,
        'Error' => true,
        'RuntimeException' => true,
        'LogicException' => true,
        'InvalidArgumentException' => true,
        'OutOfBoundsException' => true,
        'OutOfRangeException' => true,
        'OverflowException' => true,
        'UnderflowException' => true,
        'LengthException' => true,
        'DomainException' => true,
        'RangeException' => true,
        'UnexpectedValueException' => true,
        'BadMethodCallException' => true,
        'BadFunctionCallException' => true,
        'ArrayObject' => true,
        'ArrayIterator' => true,
        'Iterator' => true,
        'IteratorAggregate' => true,
        'Countable' => true,
        'Serializable' => true,
        'Throwable' => true,
        'Generator' => true,
        'Closure' => true,
        'DateTime' => true,
        'DateTimeImmutable' => true,
        'DateTimeInterface' => true,
        'DateInterval' => true,
        'DatePeriod' => true,
        'DateTimeZone' => true,
        'SplFileInfo' => true,
        'SplFileObject' => true,
        'SplTempFileObject' => true,
        'DirectoryIterator' => true,
        'RecursiveDirectoryIterator' => true,
        'FilterIterator' => true,
        'RecursiveFilterIterator' => true,
        'RecursiveIteratorIterator' => true,
        'ReflectionClass' => true,
        'ReflectionMethod' => true,
        'ReflectionProperty' => true,
        'ReflectionParameter' => true,
        'ReflectionFunction' => true,
        'PDO' => true,
        'PDOStatement' => true,
        'PDOException' => true,
        'JsonException' => true,
        'TypeError' => true,
        'ArgumentCountError' => true,
        'ArithmeticError' => true,
        'DivisionByZeroError' => true,
        'ParseError' => true,
        'CompileError' => true,
        'ValueError' => true,
        'Random\\Engine' => true,
        'Random\\Randomizer' => true,
        'IntlException' => true,
        'JsonSerializable' => true,
        'Stringable' => true,
        'ArrayAccess' => true,
        'SplStack' => true,
        'SplQueue' => true,
        'SplHeap' => true,
        'SplMinHeap' => true,
        'SplMaxHeap' => true,
        'SplDoublyLinkedList' => true,
        'SplFixedArray' => true,
        'SplPriorityQueue' => true,
        'WeakReference' => true,
        'Fiber' => true,
        'UnitEnum' => true,
        'BackedEnum' => true,
    ];

    public function getName(): string
    {
        return self::NAME;
    }

    public function requires(): array
    {
        // No dependencies on other global collectors.
        // DIT is initially computed per-file by InheritanceDepthCollector;
        // this collector overwrites with correct cross-file values.
        return [];
    }

    public function provides(): array
    {
        return [MetricName::STRUCTURE_DIT];
    }

    public function getMetricDefinitions(): array
    {
        // DIT definitions are already declared by InheritanceDepthCollector
        return [];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        // Step 1: Build class FQN → parent FQN map from dependency graph
        $parentMap = $this->buildParentMapFromGraph($graph);

        // Step 2: Recalculate DIT for all project classes
        /** @var array<string, int> $ditCache */
        $ditCache = [];

        foreach ($repository->all(SymbolType::Class_) as $classSymbol) {
            $classFqn = $this->symbolPathToFqn($classSymbol->symbolPath);
            if ($classFqn === null) {
                continue;
            }

            $dit = $this->calculateDit($classFqn, $parentMap, $ditCache);

            $repository->addScalar($classSymbol->symbolPath, MetricName::STRUCTURE_DIT, $dit);
        }
    }

    /**
     * Build class FQN → parent FQN map from the dependency graph.
     *
     * @return array<string, string> child FQN → parent FQN
     */
    private function buildParentMapFromGraph(DependencyGraphInterface $graph): array
    {
        $parentMap = [];

        foreach ($graph->getAllDependencies() as $dependency) {
            if ($dependency->type !== DependencyType::Extends) {
                continue;
            }

            $childFqn = $this->symbolPathToFqn($dependency->source);
            $parentFqn = $this->symbolPathToFqn($dependency->target);

            if ($childFqn !== null && $parentFqn !== null) {
                $parentMap[$childFqn] = $parentFqn;
            }
        }

        return $parentMap;
    }

    /**
     * Calculate DIT for a class using the global parent map.
     *
     * @param array<string, string> $parentMap child FQN → parent FQN
     * @param array<string, int> $ditCache FQN → computed DIT (memoization)
     */
    private function calculateDit(string $classFqn, array $parentMap, array &$ditCache): int
    {
        if (isset($ditCache[$classFqn])) {
            return $ditCache[$classFqn];
        }

        $parentFqn = $parentMap[$classFqn] ?? null;

        // No parent in project graph
        if ($parentFqn === null) {
            $ditCache[$classFqn] = 0;

            return 0;
        }

        // Standard PHP class
        if ($this->isStandardPhpClass($parentFqn)) {
            $ditCache[$classFqn] = 1;

            return 1;
        }

        // Prevent infinite loops (mark as computing)
        $ditCache[$classFqn] = -1;

        // Parent is in project graph → recurse
        if (isset($parentMap[$parentFqn])) {
            $parentDit = $this->calculateDit($parentFqn, $parentMap, $ditCache);

            if ($parentDit === -1) {
                // Cycle detected
                $ditCache[$classFqn] = 1;

                return 1;
            }

            $dit = 1 + $parentDit;
            $ditCache[$classFqn] = $dit;

            return $dit;
        }

        // Parent is external (not in project graph)
        // It's a root class in the project scope, or an external library class
        $parentDit = $this->resolveExternalClassDit($parentFqn);
        $dit = 1 + $parentDit;
        $ditCache[$classFqn] = $dit;

        return $dit;
    }

    private function isStandardPhpClass(string $fqn): bool
    {
        $normalized = ltrim($fqn, '\\');

        return isset(self::STANDARD_PHP_CLASSES[$normalized]);
    }

    /**
     * Try to resolve DIT for an external class via reflection.
     *
     * @return int DIT of the external class, or 0 if cannot resolve
     */
    private function resolveExternalClassDit(string $classFqn): int
    {
        $normalized = ltrim($classFqn, '\\');

        if (!class_exists($normalized, true) && !interface_exists($normalized, true)) {
            return 0;
        }

        try {
            $reflection = new ReflectionClass($normalized);
            $depth = 0;
            $current = $reflection;

            while (($parent = $current->getParentClass()) !== false) {
                ++$depth;

                if ($this->isStandardPhpClass($parent->getName())) {
                    break;
                }

                $current = $parent;
            }

            return $depth;
        } catch (ReflectionException) {
            return 0;
        }
    }

    private function symbolPathToFqn(SymbolPath $path): ?string
    {
        if ($path->type === null) {
            return null;
        }

        if ($path->namespace !== null && $path->namespace !== '') {
            return $path->namespace . '\\' . $path->type;
        }

        return $path->type;
    }
}
