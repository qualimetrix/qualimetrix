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
use SplFileInfo;

/**
 * Collects Depth of Inheritance Tree (DIT) metric for classes.
 *
 * DIT measures how deep a class is in the inheritance hierarchy:
 * - DIT = 0: class has no parent
 * - DIT = N: class is N levels deep in the inheritance tree
 *
 * Standard PHP classes (stdClass, Exception, etc.) are considered root.
 * A parent this file does not declare counts as one level here; the depth the
 * report publishes comes from {@see DitGlobalCollector}, which resolves such a
 * parent from the dependency graph.
 *
 * Anonymous classes are ignored.
 *
 * This collector measures DIT but does not declare it: the definition belongs
 * to {@see DitGlobalCollector}, which writes the value the report publishes.
 * Declaring it here as well would put DIT into the first aggregation pass,
 * whose class values the global pass has not yet corrected.
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

        // A parent this file does not declare is one the global pass resolves
        // from the dependency graph, which is the only pass whose depth the
        // report publishes. Asking an autoloader here answered a question about
        // the analysed project with this tool's own install, and the answer was
        // then overwritten anyway.
        return 1;
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

}
