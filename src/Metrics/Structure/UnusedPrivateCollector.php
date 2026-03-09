<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Core\Metric\ClassMetricsProviderInterface;
use AiMessDetector\Core\Metric\ClassWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use SplFileInfo;

/**
 * Collects unused private member metrics for classes.
 *
 * Detects private methods, properties, and constants that are declared
 * but never referenced within the same class.
 *
 * Metrics per class:
 * - unusedPrivate.method.count: number of unused private methods
 * - unusedPrivate.method.line.{i}: line number of each unused method
 * - unusedPrivate.property.count: number of unused private properties
 * - unusedPrivate.property.line.{i}: line number of each unused property
 * - unusedPrivate.constant.count: number of unused private constants
 * - unusedPrivate.constant.line.{i}: line number of each unused constant
 * - unusedPrivate.total: total count of all unused private members
 */
final class UnusedPrivateCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'unused-private';

    public const string METRIC_METHOD_COUNT = 'unusedPrivate.method.count';
    public const string METRIC_PROPERTY_COUNT = 'unusedPrivate.property.count';
    public const string METRIC_CONSTANT_COUNT = 'unusedPrivate.constant.count';
    public const string METRIC_TOTAL = 'unusedPrivate.total';

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
            self::METRIC_METHOD_COUNT,
            self::METRIC_PROPERTY_COUNT,
            self::METRIC_CONSTANT_COUNT,
            self::METRIC_TOTAL,
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

        $bag = $bag
            ->with(self::METRIC_METHOD_COUNT . ':' . $classFqn, \count($unusedMethods))
            ->with(self::METRIC_PROPERTY_COUNT . ':' . $classFqn, \count($unusedProperties))
            ->with(self::METRIC_CONSTANT_COUNT . ':' . $classFqn, \count($unusedConstants))
            ->with(
                self::METRIC_TOTAL . ':' . $classFqn,
                \count($unusedMethods) + \count($unusedProperties) + \count($unusedConstants),
            );

        $bag = $this->addLineMetrics($bag, $classFqn, 'method', $unusedMethods);
        $bag = $this->addLineMetrics($bag, $classFqn, 'property', $unusedProperties);
        $bag = $this->addLineMetrics($bag, $classFqn, 'constant', $unusedConstants);

        return $bag;
    }

    private function buildClassMetricBag(UnusedPrivateClassData $data): MetricBag
    {
        $unusedMethods = $data->getUnusedMethods();
        $unusedProperties = $data->getUnusedProperties();
        $unusedConstants = $data->getUnusedConstants();

        $bag = (new MetricBag())
            ->with(self::METRIC_METHOD_COUNT, \count($unusedMethods))
            ->with(self::METRIC_PROPERTY_COUNT, \count($unusedProperties))
            ->with(self::METRIC_CONSTANT_COUNT, \count($unusedConstants))
            ->with(
                self::METRIC_TOTAL,
                \count($unusedMethods) + \count($unusedProperties) + \count($unusedConstants),
            );

        $bag = $this->addLineMetrics($bag, null, 'method', $unusedMethods);
        $bag = $this->addLineMetrics($bag, null, 'property', $unusedProperties);
        $bag = $this->addLineMetrics($bag, null, 'constant', $unusedConstants);

        return $bag;
    }

    /**
     * @param array<string, int> $unusedMembers name => line
     */
    private function addLineMetrics(MetricBag $bag, ?string $classFqn, string $type, array $unusedMembers): MetricBag
    {
        $suffix = $classFqn !== null ? ':' . $classFqn : '';
        $i = 0;

        foreach ($unusedMembers as $line) {
            $bag = $bag->with("unusedPrivate.{$type}.line.{$i}{$suffix}", $line);
            $i++;
        }

        return $bag;
    }
}
