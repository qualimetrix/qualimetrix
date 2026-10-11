<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Override;
use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareTrait;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Captures exact declaration facts for later LCOM4 calculation.
 *
 * LCOM4 measures class cohesion by counting connected components in the graph where:
 * - Vertices = instance methods in the class (static methods are excluded)
 * - Edges = (m1, m2) if m1 and m2 share a property OR one calls the other via $this->
 *
 * Interpretation:
 * - LCOM = 1: perfectly cohesive class (all methods share properties)
 * - LCOM > 1: class could potentially be split into LCOM separate classes
 * Anonymous classes are ignored.
 */
final class LcomCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;

    public const string FACTS = 'cohesion.lcom-facts';

    private const NAME = 'lcom';

    public function __construct()
    {
        $this->visitor = new LcomVisitor();
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
        return [self::FACTS];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        return new MetricBag();
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof LcomVisitor);

        $result = [];

        foreach ($this->visitor->getClassData() as $classData) {
            $bag = new MetricBag();
            foreach ($classData->rows() as $row) {
                $bag = $bag->withEntry(self::FACTS, $row);
            }

            $result[] = $this->classWithMetrics(SymbolPath::forClass($classData->namespace ?? '', $classData->className), $file, $classData->startFilePos, $classData->line, $bag);
        }

        return $result;
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        return [];
    }
}
