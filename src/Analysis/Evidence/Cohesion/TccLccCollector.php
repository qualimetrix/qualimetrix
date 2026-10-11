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
 * Captures exact declaration facts for later TCC and LCC calculation.
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
 *
 * The global composition publishes no value for a class with fewer than two tracked methods
 * (no pair: the ratio is undefined) or without declared instance properties.
 * The latter deviates from Bieman & Kang, whose formula gives such a class
 * TCC = LCC = 0; a stateless class is treated as outside the metric's
 * domain rather than as maximally incohesive.
 */
final class TccLccCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;
    use CohesionFactProjectionTrait;

    public const string FACTS = 'cohesion.tcc-facts';

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
        \assert($this->visitor instanceof TccLccVisitor);

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
