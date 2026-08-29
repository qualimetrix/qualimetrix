<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Override;
use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareTrait;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Collects LCOM4 (Lack of Cohesion of Methods) metric for classes.
 *
 * LCOM4 measures class cohesion by counting connected components in the graph where:
 * - Vertices = instance methods in the class (static methods are excluded)
 * - Edges = (m1, m2) if m1 and m2 share a property OR one calls the other via $this->
 *
 * Interpretation:
 * - LCOM = 1: perfectly cohesive class (all methods share properties)
 * - LCOM > 1: class could potentially be split into LCOM separate classes
 * - LCOM = 0: class has no methods
 *
 * Anonymous classes are ignored.
 */
final class LcomCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface, LcomCollectionConfigurableInterface
{
    use DeclarationIndexAwareTrait;

    private const NAME = 'lcom';

    private LcomCollectionConfiguration $runtimeConfiguration;

    public function __construct()
    {
        $this->visitor = new LcomVisitor();
        $this->runtimeConfiguration = LcomCollectionConfiguration::defaults();
    }

    public function applyLcomCollectionConfiguration(LcomCollectionConfiguration $configuration): void
    {
        $this->runtimeConfiguration = $configuration;
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
        return [MetricName::COHESION_LCOM];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof LcomVisitor);

        foreach ($this->visitor->getClassData() as $classFqn => $classData) {
            $lcom = $this->adjustedLcom($classData);

            $bag = $bag->with(MetricName::COHESION_LCOM . ':' . $classFqn, $lcom);
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof LcomVisitor);

        $result = [];

        foreach ($this->visitor->getClassData() as $classData) {
            $lcom = $this->adjustedLcom($classData);

            $bag = (new MetricBag())->with(MetricName::COHESION_LCOM, $lcom);

            $result[] = $this->classWithMetrics(SymbolPath::forClass($classData->namespace ?? '', $classData->className), $file, $classData->startFilePos, $classData->line, $bag);
        }

        return $result;
    }

    /**
     * Returns LCOM adjusted for trivial classes (null objects, stubs).
     *
     * Classes where ALL methods are trivial (empty, return constant/null)
     * get LCOM=1 instead of the calculated value, since they lack cohesion
     * by design, not by poor structure.
     */
    private function adjustedLcom(LcomClassData $classData): int
    {
        $lcom = $classData->calculateLcom($this->runtimeConfiguration->excludedMethods);

        return ($lcom > 1 && $classData->hasOnlyTrivialMethods()) ? 1 : $lcom;
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COHESION_LCOM,
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
