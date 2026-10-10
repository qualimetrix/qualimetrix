<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareTrait;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Publishes raw declaration facts; final RFC is computed after collection.
 *
 * @qmx-threshold coupling.instability warning=0.800001 -- The collection adapter reads exact declaration and metric transports; its small incoming population does not model that required outward composition.
 */
final class RfcCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;

    public const string FACTS = 'coupling.rfc-facts';

    public function __construct()
    {
        $this->visitor = new RfcVisitor();
    }

    public function getName(): string
    {
        return 'rfc';
    }

    public function provides(): array
    {
        return [self::FACTS];
    }

    /** @param Node[] $ast */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        return new MetricBag();
    }

    /** @return list<ClassWithMetrics> */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof RfcVisitor);
        $classes = [];
        foreach ($this->visitor->getClassesData() as $facts) {
            $bag = new MetricBag();
            foreach ($facts->rows as $row) {
                $bag = $bag->withEntry(self::FACTS, $row);
            }
            $classes[] = $this->classWithMetrics(SymbolPath::forClass($facts->namespace, $facts->className), $file, $facts->startFilePos, $facts->line, $bag);
        }

        return $classes;
    }
}
