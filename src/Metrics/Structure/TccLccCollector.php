<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\ClassMetricsProviderInterface;
use AiMessDetector\Core\Metric\ClassWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects TCC and LCC (Tight/Loose Class Cohesion) metrics for classes.
 *
 * TCC (Tight Class Cohesion):
 * - Measures direct connections via shared properties
 * - TCC = NDC / NP, where NDC = directly connected pairs, NP = max possible pairs
 * - Range: 0.0 (no cohesion) to 1.0 (perfect cohesion)
 *
 * LCC (Loose Class Cohesion):
 * - Includes transitive connections (methods connected via intermediaries)
 * - LCC = NIC / NP, where NIC = all connected pairs (direct + transitive)
 * - Range: 0.0 (no cohesion) to 1.0 (perfect cohesion)
 *
 * Interpretation:
 * - TCC >= 0.5: Good cohesion
 * - TCC < 0.3: Low cohesion, consider splitting the class
 * - TCC = 1.0: All methods share properties (perfect cohesion)
 *
 * Only PUBLIC methods are considered (unlike LCOM which considers all methods).
 * Anonymous classes are ignored.
 */
final class TccLccCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'tcc_lcc';

    public function __construct()
    {
        $this->visitor = new TccLccVisitor();
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
        return [MetricName::COHESION_TCC, MetricName::COHESION_LCC, MetricName::COHESION_PURE_METHOD_COUNT];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof TccLccVisitor);

        foreach ($this->visitor->getClassData() as $classFqn => $classData) {
            // Skip classes with fewer than 2 public instance methods — TCC/LCC is not
            // meaningful for them. This covers all-static utility classes, empty classes,
            // single-method classes, and classes with only constructors/destructors.
            // The health formula handles missing TCC via null-coalescing (tcc ?? 0.5).
            if (\count($classData->getMethods()) < 2) {
                continue;
            }

            // Skip classes with no declared instance properties — TCC is structurally
            // undefined (not 0) when there are no properties to share between methods.
            // Emitting TCC=0.0 for these classes would drag down cohesion averages
            // and misrepresent the health of namespaces with many property-less classes.
            // By-design: pureMethodCount is also skipped here — for propertyCount=0 classes
            // the health formula uses tcc ?? 0.5 (neutral default), which is appropriate
            // since TCC is undefined rather than low.
            if ($classData->getPropertyCount() === 0) {
                continue;
            }

            $tcc = $classData->calculateTcc();
            $lcc = $classData->calculateLcc();
            $pureCount = $this->countPureMethods($classData);

            // Store metrics with class FQN as key
            $bag = $bag->with(MetricName::COHESION_TCC . ':' . $classFqn, round($tcc, 3));
            $bag = $bag->with(MetricName::COHESION_LCC . ':' . $classFqn, round($lcc, 3));
            $bag = $bag->with(MetricName::COHESION_PURE_METHOD_COUNT . ':' . $classFqn, $pureCount);
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        \assert($this->visitor instanceof TccLccVisitor);

        $result = [];

        foreach ($this->visitor->getClassData() as $classData) {
            // Skip classes with fewer than 2 public instance methods (see collect() comment)
            if (\count($classData->getMethods()) < 2) {
                continue;
            }

            // Skip classes with no declared instance properties (see collect() comment)
            if ($classData->getPropertyCount() === 0) {
                continue;
            }

            $tcc = round($classData->calculateTcc(), 3);
            $lcc = round($classData->calculateLcc(), 3);
            $pureCount = $this->countPureMethods($classData);

            $bag = (new MetricBag())
                ->with(MetricName::COHESION_TCC, $tcc)
                ->with(MetricName::COHESION_LCC, $lcc)
                ->with(MetricName::COHESION_PURE_METHOD_COUNT, $pureCount);

            $result[] = new ClassWithMetrics(
                namespace: $classData->namespace,
                class: $classData->className,
                line: $classData->line,
                metrics: $bag,
            );
        }

        return $result;
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COHESION_TCC,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COHESION_LCC,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COHESION_PURE_METHOD_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: [],
            ),
        ];
    }

    /**
     * Counts methods with zero property access ("pure" methods).
     *
     * These are typically interface contract implementations (getName(), getDescription())
     * that inflate TCC denominator and LCOM without reflecting actual cohesion issues.
     * Note: only $this->property accesses are tracked; static property accesses
     * (self::$x, static::$x) are not considered — this matches TCC semantics.
     */
    private function countPureMethods(TccLccClassData $classData): int
    {
        $count = 0;

        foreach ($classData->getMethods() as $method) {
            if (\count($classData->getPropertiesAccessedBy($method)) === 0) {
                ++$count;
            }
        }

        return $count;
    }
}
