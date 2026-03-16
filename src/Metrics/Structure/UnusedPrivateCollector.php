<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Core\Metric\ClassMetricsProviderInterface;
use AiMessDetector\Core\Metric\ClassWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use SplFileInfo;

/**
 * Collects unused private member metrics for classes.
 *
 * Detects private methods, properties, and constants that are declared
 * but never referenced within the same class.
 *
 * DataBag entries per class (keyed by class FQN suffix):
 * - unusedPrivate.method: entries with ['line' => int, 'name' => string]
 * - unusedPrivate.property: entries with ['line' => int, 'name' => string]
 * - unusedPrivate.constant: entries with ['line' => int, 'name' => string]
 *
 * Scalar metrics per class:
 * - unusedPrivate.total: total count of all unused private members
 */
final class UnusedPrivateCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'unused-private';

    public const string ENTRY_METHOD = MetricName::STRUCTURE_UNUSED_PRIVATE_METHOD;
    public const string ENTRY_PROPERTY = MetricName::STRUCTURE_UNUSED_PRIVATE_PROPERTY;
    public const string ENTRY_CONSTANT = MetricName::STRUCTURE_UNUSED_PRIVATE_CONSTANT;

    public function __construct()
    {
        $this->visitor = new UnusedPrivateVisitor();
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
        return [
            self::ENTRY_METHOD,
            self::ENTRY_PROPERTY,
            self::ENTRY_CONSTANT,
            MetricName::STRUCTURE_UNUSED_PRIVATE_TOTAL,
        ];
    }

    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof UnusedPrivateVisitor);

        $bag = new MetricBag();

        foreach ($this->visitor->getClassData() as $classFqn => $classData) {
            $bag = $this->addClassMetrics($bag, $classFqn, $classData);
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        \assert($this->visitor instanceof UnusedPrivateVisitor);

        $result = [];

        foreach ($this->visitor->getClassData() as $classData) {
            $bag = $this->buildClassMetricBag($classData);

            $result[] = new ClassWithMetrics(
                namespace: $classData->namespace,
                class: $classData->className,
                line: $classData->line,
                metrics: $bag,
            );
        }

        return $result;
    }

    #[Override]
    public function getMetricDefinitions(): array
    {
        return [];
    }

    private function addClassMetrics(MetricBag $bag, string $classFqn, UnusedPrivateClassData $data): MetricBag
    {
        $unusedMethods = $data->getUnusedMethods();
        $unusedProperties = $data->getUnusedProperties();
        $unusedConstants = $data->getUnusedConstants();

        $bag = $bag->with(
            MetricName::STRUCTURE_UNUSED_PRIVATE_TOTAL . ':' . $classFqn,
            \count($unusedMethods) + \count($unusedProperties) + \count($unusedConstants),
        );

        $bag = $this->addEntries($bag, $classFqn, self::ENTRY_METHOD, $unusedMethods);
        $bag = $this->addEntries($bag, $classFqn, self::ENTRY_PROPERTY, $unusedProperties);
        $bag = $this->addEntries($bag, $classFqn, self::ENTRY_CONSTANT, $unusedConstants);

        return $bag;
    }

    private function buildClassMetricBag(UnusedPrivateClassData $data): MetricBag
    {
        $unusedMethods = $data->getUnusedMethods();
        $unusedProperties = $data->getUnusedProperties();
        $unusedConstants = $data->getUnusedConstants();

        $bag = (new MetricBag())->with(
            MetricName::STRUCTURE_UNUSED_PRIVATE_TOTAL,
            \count($unusedMethods) + \count($unusedProperties) + \count($unusedConstants),
        );

        $bag = $this->addEntries($bag, null, self::ENTRY_METHOD, $unusedMethods);
        $bag = $this->addEntries($bag, null, self::ENTRY_PROPERTY, $unusedProperties);
        $bag = $this->addEntries($bag, null, self::ENTRY_CONSTANT, $unusedConstants);

        return $bag;
    }

    /**
     * @param array<string, int> $unusedMembers name => line
     */
    private function addEntries(MetricBag $bag, ?string $classFqn, string $entryKey, array $unusedMembers): MetricBag
    {
        $suffix = $classFqn !== null ? ':' . $classFqn : '';

        foreach ($unusedMembers as $name => $line) {
            $bag = $bag->withEntry("{$entryKey}{$suffix}", ['line' => $line, 'name' => $name]);
        }

        return $bag;
    }
}
