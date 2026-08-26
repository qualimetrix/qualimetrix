<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\MockObject\Stub;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
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
 */
trait MetricRepositoryTestHelper
{
    /**
     * @param list<SymbolInfo> $namespaces
     * @param array<string, MetricBag> $namespaceMetrics
     * @param list<SymbolInfo> $classes
     * @param array<string, MetricBag> $classMetrics
     */
    private function createMetricRepository(
        MetricBag $projectMetrics,
        array $namespaces = [],
        array $namespaceMetrics = [],
        array $classes = [],
        array $classMetrics = [],
    ): MetricRepositoryInterface {
        /** @var MetricRepositoryInterface&Stub $mock */
        $mock = self::createStub(MetricRepositoryInterface::class);

        $mock->method('get')
            ->willReturnCallback(function (SymbolPath $symbol) use ($projectMetrics, $namespaceMetrics, $classMetrics): MetricBag {
                $canonical = $symbol->toCanonical();

                if ($symbol->getType() === SymbolType::Project) {
                    return $projectMetrics;
                }

                if (isset($namespaceMetrics[$canonical])) {
                    return $namespaceMetrics[$canonical];
                }

                if (isset($classMetrics[$canonical])) {
                    return $classMetrics[$canonical];
                }

                return new MetricBag();
            });

        $mock->method('all')
            ->willReturnCallback(function (SymbolLevel $level) use ($namespaces, $classes): iterable {
                return match ($level) {
                    SymbolLevel::Namespace_ => $namespaces,
                    SymbolLevel::Class_ => $classes,
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
