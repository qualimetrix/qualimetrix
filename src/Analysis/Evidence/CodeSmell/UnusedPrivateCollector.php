<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Override;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareTrait;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\DataBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
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
final class UnusedPrivateCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;

    private const NAME = 'unused-private';

    public const string ENTRY_METHOD = MetricName::CODE_SMELL_UNUSED_PRIVATE_METHOD;
    public const string ENTRY_PROPERTY = MetricName::CODE_SMELL_UNUSED_PRIVATE_PROPERTY;
    public const string ENTRY_CONSTANT = MetricName::CODE_SMELL_UNUSED_PRIVATE_CONSTANT;

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
            MetricName::CODE_SMELL_UNUSED_PRIVATE_TOTAL,
        ];
    }

    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof UnusedPrivateVisitor);

        $bag = new MetricBag();

        foreach ($this->visitor->getClassData() as $classData) {
            $classFqn = $classData->namespace !== null && $classData->namespace !== ''
                ? $classData->namespace . '\\' . $classData->className
                : $classData->className;
            $bag = $this->addClassMetrics($bag, $classData, $classFqn);
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof UnusedPrivateVisitor);

        $result = [];

        foreach ($this->visitor->getClassData() as $classData) {
            $bag = $this->addClassMetrics(new MetricBag(), $classData, null);

            $result[] = $this->classWithMetrics(SymbolPath::forClass($classData->namespace ?? '', $classData->className), $file, $classData->startFilePos, $classData->line, $bag);
        }

        return $result;
    }

    #[Override]
    public function getMetricDefinitions(): array
    {
        return [new MetricDefinition(name: MetricName::CODE_SMELL_UNUSED_PRIVATE_TOTAL, collectedAt: SymbolLevel::Class_)];
    }

    private function addClassMetrics(MetricBag $bag, UnusedPrivateClassData $data, ?string $classFqn): MetricBag
    {
        $unusedMethods = $data->getUnusedMethods();
        $unusedProperties = $data->getUnusedProperties();
        $unusedConstants = $data->getUnusedConstants();
        $suffix = $classFqn === null ? '' : ':' . $classFqn;

        $bag = $bag->with(
            MetricName::CODE_SMELL_UNUSED_PRIVATE_TOTAL . $suffix,
            \count($unusedMethods) + \count($unusedProperties) + \count($unusedConstants),
        );

        $bag = $this->addEntries($bag, $classFqn, self::ENTRY_METHOD, $unusedMethods);
        $bag = $this->addEntries($bag, $classFqn, self::ENTRY_PROPERTY, $unusedProperties);
        $bag = $this->addEntries($bag, $classFqn, self::ENTRY_CONSTANT, $unusedConstants);

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
