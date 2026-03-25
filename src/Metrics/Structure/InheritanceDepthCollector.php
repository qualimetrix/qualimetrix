<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use Override;
use PhpParser\Node;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\ClassMetricsProviderInterface;
use Qualimetrix\Core\Metric\ClassWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\AbstractCollector;
use ReflectionClass;
use ReflectionException;
use SplFileInfo;

/**
 * Collects Depth of Inheritance Tree (DIT) metric for classes.
 *
 * DIT measures how deep a class is in the inheritance hierarchy:
 * - DIT = 0: class has no parent
 * - DIT = N: class is N levels deep in the inheritance tree
 *
 * Standard PHP classes (stdClass, Exception, etc.) are considered root.
 * External classes not in the current file are resolved via autoload if possible,
 * otherwise conservatively estimated as DIT = 1.
 *
 * Anonymous classes are ignored.
 */
final class InheritanceDepthCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'inheritance-depth';

    /**
     * Standard PHP classes that are considered root (DIT = 0 when extending them).
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

    public function __construct()
    {
        $this->visitor = new InheritanceDepthVisitor();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [MetricName::STRUCTURE_DIT];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof InheritanceDepthVisitor);

        $classParents = $this->visitor->getClassParents();

        foreach ($classParents as $classFqn => $parentFqn) {
            $dit = $this->calculateDit($classFqn, $classParents);
            $bag = $bag->with(MetricName::STRUCTURE_DIT . ':' . $classFqn, $dit);

            // Note: Parent information is stored in dependency graph as DependencyType::Extends
            // NocCollector will use that information for NOC calculation
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        \assert($this->visitor instanceof InheritanceDepthVisitor);

        $result = [];
        $classParents = $this->visitor->getClassParents();

        foreach ($this->visitor->getClassInfo() as $classFqn => $info) {
            $dit = $this->calculateDit($classFqn, $classParents);

            $bag = (new MetricBag())->with(MetricName::STRUCTURE_DIT, $dit);

            // Note: Parent information is stored in dependency graph as DependencyType::Extends
            // NocCollector will use that information for NOC calculation

            $result[] = new ClassWithMetrics(
                namespace: $info->namespace,
                class: $info->className,
                line: $info->line,
                metrics: $bag,
            );
        }

        return $result;
    }

    /**
     * Calculate DIT for a class.
     *
     * @param array<string, string|null> $classParents
     * @param array<string, true> $visited To prevent infinite loops
     */
    private function calculateDit(string $classFqn, array $classParents, array $visited = []): int
    {
        // Get parent
        $parentFqn = $classParents[$classFqn] ?? null;

        // No parent = DIT 0
        if ($parentFqn === null) {
            return 0;
        }

        // Check for standard PHP class
        if ($this->isStandardPhpClass($parentFqn)) {
            return 1;
        }

        // Prevent infinite loops
        if (isset($visited[$classFqn])) {
            return 1;
        }
        $visited[$classFqn] = true;

        // If parent is in current file, calculate recursively
        if (isset($classParents[$parentFqn])) {
            return 1 + $this->calculateDit($parentFqn, $classParents, $visited);
        }

        // Try to resolve via autoload
        $parentDit = $this->resolveExternalClassDit($parentFqn);

        return 1 + $parentDit;
    }

    /**
     * Check if class is a standard PHP class.
     */
    private function isStandardPhpClass(string $fqn): bool
    {
        // Remove leading backslash if present
        $normalized = ltrim($fqn, '\\');

        // Direct match covers both unqualified names (e.g. "Exception")
        // and FQNs (e.g. "RuntimeException"). Namespaced names like
        // "App\Exception" won't match, preventing false positives.
        return isset(self::STANDARD_PHP_CLASSES[$normalized]);
    }

    /**
     * Try to resolve DIT for an external class via autoload.
     *
     * @return int DIT of parent, or 0 if cannot resolve (conservative)
     */
    private function resolveExternalClassDit(string $classFqn): int
    {
        // Normalize FQN
        $normalized = ltrim($classFqn, '\\');

        // Try to load the class
        if (!class_exists($normalized, true) && !interface_exists($normalized, true)) {
            // Cannot resolve - assume it's a root class
            return 0;
        }

        try {
            $reflection = new ReflectionClass($normalized);

            return $this->calculateReflectionDit($reflection);
        } catch (ReflectionException) {
            // Cannot reflect - assume root
            return 0;
        }
    }

    /**
     * Calculate DIT using reflection.
     *
     * @param ReflectionClass<object> $class
     */
    private function calculateReflectionDit(ReflectionClass $class): int
    {
        $depth = 0;
        $current = $class;

        while (($parent = $current->getParentClass()) !== false) {
            ++$depth;

            // Stop at standard PHP classes (depth already incremented for extending them)
            if ($this->isStandardPhpClass($parent->getName())) {
                break;
            }

            $current = $parent;
        }

        return $depth;
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::STRUCTURE_DIT,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
        ];
    }
}
