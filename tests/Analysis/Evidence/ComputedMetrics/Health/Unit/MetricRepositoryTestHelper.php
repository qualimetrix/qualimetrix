<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use LogicException;
use PHPUnit\Framework\MockObject\Stub;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Shared helper for creating MetricRepositoryInterface mocks in Health tests.
 *
 * Requires the using class to extend PHPUnit\Framework\TestCase (for createMock).
 *
 * @mixin \PHPUnit\Framework\TestCase
 *
 * @param list<SymbolInfo> $namespaces
 * @param array<string, MetricBag> $namespaceMetrics
 * @param list<SymbolInfo> $classes
 * @param array<string, MetricBag> $classMetrics
 * @param list<SymbolInfo> $callables
 */
trait MetricRepositoryTestHelper
{
    private ?MetricBag $fixtureProjectMetrics = null;

    private function defaultDefinitionCatalog(): \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface
    {
        $catalog = self::createStub(\Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturnCallback(function (): array {
            $defaults = \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults::getDefaults();
            if ($this->fixtureProjectMetrics === null) {
                return array_values($defaults);
            }
            return array_values(array_filter($defaults, fn($definition): bool => $definition->name === 'health.overall' || $this->fixtureProjectMetrics->has($definition->name)));
        });
        return $catalog;
    }

    private static function exactClassSubject(SymbolPath $symbol, string $file): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(
            $symbol,
            RelativePath::fromString($file),
            DeclarationOrdinal::fromRank(0),
        ));
    }

    /**
     * @param list<SymbolInfo> $namespaces
     * @param array<string, MetricBag> $namespaceMetrics
     * @param list<SymbolInfo> $classes
     * @param array<string, MetricBag> $classMetrics
     * @param list<SymbolInfo> $callables
     */
    private function createMetricRepository(
        MetricBag $projectMetrics,
        array $namespaces = [],
        array $namespaceMetrics = [],
        array $classes = [],
        array $classMetrics = [],
        array $callables = [],
    ): MetricRepositoryInterface {
        $this->fixtureProjectMetrics = $projectMetrics;
        $exactClasses = [];
        foreach ($classes as $class) {
            if ($class->symbolPath->getType() !== SymbolType::Class_ || $class->subject?->declarationPath() === null) {
                throw new LogicException('Health fixture class requires an exact declaration subject');
            }
            $exactClasses[$class->subject->toCanonical()] = true;
        }
        foreach (array_keys($classMetrics) as $key) {
            if (!isset($exactClasses[$key])) {
                throw new LogicException('Health fixture class metrics require an explicit exact declaration: ' . $key);
            }
        }
        foreach ($callables as $callable) {
            if ($callable->subject?->declarationPath() === null || $callable->callableKind === null) {
                throw new LogicException('Health fixture callable requires a stored exact declaration row');
            }
        }

        /** @var MetricRepositoryInterface&Stub $mock */
        $mock = self::createStub(MetricRepositoryInterface::class);

        $mock->method('get')
            ->willReturnCallback(function (SymbolPath $symbol) use ($projectMetrics, $namespaceMetrics): MetricBag {
                if ($symbol->getType() === SymbolType::Class_) {
                    throw new LogicException('Health fixture class reads require an exact subject');
                }
                $canonical = $symbol->toCanonical();

                if ($symbol->getType() === SymbolType::Project) {
                    return $projectMetrics;
                }

                if (isset($namespaceMetrics[$canonical])) {
                    return $namespaceMetrics[$canonical];
                }

                return new MetricBag();
            });

        $mock->method('getSubject')
            ->willReturnCallback(static function (MetricSubject $subject) use ($classMetrics, $exactClasses, $projectMetrics, $namespaceMetrics): MetricBag {
                $aggregate = $subject->aggregatePath();
                if ($aggregate?->getType() === SymbolType::Project) {
                    return $projectMetrics;
                }
                if ($aggregate !== null) {
                    return $namespaceMetrics[$aggregate->toCanonical()] ?? new MetricBag();
                }
                if ($subject->logicalClassPath() !== null) {
                    return new MetricBag();
                }
                $key = $subject->toCanonical();
                if (!isset($exactClasses[$key])) {
                    throw new LogicException('Health fixture has no exact class declaration: ' . $key);
                }

                return $classMetrics[$key] ?? new MetricBag();
            });
        $mock->method('hasSubject')
            ->willReturnCallback(static fn(MetricSubject $subject): bool => isset($exactClasses[$subject->toCanonical()]));
        $mock->method('allClassDeclarations')->willReturn($classes);
        $mock->method('allLogicalClasses')->willReturn([]);
        $mock->method('allCallables')->willReturn($callables);

        $mock->method('all')
            ->willReturnCallback(function (SymbolLevel $level) use ($namespaces): iterable {
                return match ($level) {
                    SymbolLevel::Namespace_ => $namespaces,
                    SymbolLevel::Class_ => throw new LogicException('Health fixture class enumeration requires exact declarations'),
                    default => [],
                };
            });

        $mock->method('getNamespaces')
            ->willReturn(array_map(
                static fn(SymbolInfo $info): string => $info->symbolPath->toString(),
                $namespaces,
            ));

        return $mock;
    }
}
