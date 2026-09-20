<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareTrait;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;
use ReflectionException;
use SplFileInfo;
use Throwable;

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
 *
 * This collector measures DIT but does not declare it: the definition belongs
 * to {@see DitGlobalCollector}, which writes the value the report publishes.
 * Declaring it here as well would put DIT into the first aggregation pass,
 * whose class values the global pass has not yet corrected.
 *
 * @qmx-ignore health.cohesion -- Visitor-backed collector methods are intentionally independent protocol operations.
 */
final class InheritanceDepthCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;

    private const NAME = 'inheritance-depth';

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
        return [MetricName::DESIGN_DIT];
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
            $bag = $bag->with(MetricName::DESIGN_DIT . ':' . $classFqn, $dit);

            // Note: Parent information is stored in dependency graph as DependencyType::Extends
            // NocCollector will use that information for NOC calculation
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof InheritanceDepthVisitor);

        $result = [];
        $classParents = $this->visitor->getClassParents();

        foreach ($this->visitor->getClassInfo() as $classFqn => $info) {
            $dit = $this->calculateDit($classFqn, $classParents);

            $bag = (new MetricBag())->with(MetricName::DESIGN_DIT, $dit);

            // Note: Parent information is stored in dependency graph as DependencyType::Extends
            // NocCollector will use that information for NOC calculation

            $result[] = $this->classWithMetrics(SymbolPath::forClass($info->namespace ?? '', $info->className), $file, $info->startFilePos, $info->line, $bag);
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

        return PhpBuiltinClassRegistry::isBuiltin($normalized);
    }

    /**
     * Try to resolve DIT for an external class via autoload.
     *
     * The autoloader consulted here belongs to this tool, not to the analysed
     * project, so an analysed FQCN can map onto the tool's own vendored copy.
     * When that copy is reachable but names a parent the tool's install never
     * ships, loading it throws instead of returning false -- so every failure
     * to load has to read as "unresolved", not as a broken analysed file.
     *
     * @return int DIT of parent, or 0 if cannot resolve (conservative)
     */
    private function resolveExternalClassDit(string $classFqn): int
    {
        // Normalize FQN
        $normalized = ltrim($classFqn, '\\');

        // Only the load step runs someone else's code, and it fails with a
        // plain Error carrying nothing to match on, so it takes the widest
        // catch there is. The walk below keeps a narrow one: it cannot
        // autoload -- a class is not declared until its ancestors are -- so
        // anything thrown there is this tool's own defect and must stay loud.
        try {
            // Try to load the class
            if (!class_exists($normalized, true) && !interface_exists($normalized, true)) {
                // Cannot resolve - assume it's a root class
                return 0;
            }
        } catch (Throwable) {
            return 0;
        }

        try {
            return $this->calculateReflectionDit(new ReflectionClass($normalized));
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

}
