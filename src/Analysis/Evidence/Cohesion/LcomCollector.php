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
use SplFileInfo;

/**
 * Captures exact declaration facts for later LCOM4 calculation.
 *
 * LCOM4 measures class cohesion by counting connected components in the graph where:
 * - Vertices = instance methods in the class; static methods, constructors, destructors
 *   and the private helpers only they reach through direct calls are excluded
 * - Edges = (m1, m2) if m1 and m2 share a property OR one calls the other via $this->
 *
 * The facts keep calls from closures, arrow functions and first-class callables apart
 * from direct calls, so a handler a constructor registers is not taken for initialization.
 *
 * Interpretation:
 * - LCOM = 1: perfectly cohesive class (all methods share properties)
 * - LCOM > 1: class could potentially be split into LCOM separate classes
 * Anonymous classes are ignored.
 */
final class LcomCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;
    use CohesionFactProjectionTrait;

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

        return $this->classesWithCohesionFacts($file, $this->visitor->getClassData(), self::FACTS);
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
