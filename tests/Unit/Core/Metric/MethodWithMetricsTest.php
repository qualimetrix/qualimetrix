<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Metric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;

#[CoversClass(MethodWithMetrics::class)]
final class MethodWithMetricsTest extends TestCase
{
    public function testGetSymbolPathForClassMethod(): void
    {
        $metrics = (new MetricBag())->with('ccn', 5);

        $method = new MethodWithMetrics(
            namespace: 'App\\Service',
            class: 'UserService',
            method: 'calculate',
            line: 42,
            metrics: $metrics,
        );

        $symbolPath = $method->getSymbolPath();

        self::assertNotNull($symbolPath);
        self::assertSame('method:App\\Service\\UserService::calculate', $symbolPath->toCanonical());
    }

    public function testGetSymbolPathForGlobalFunction(): void
    {
        $metrics = (new MetricBag())->with('ccn', 2);

        $method = new MethodWithMetrics(
            namespace: 'App\\Utils',
            class: null,
            method: 'helper',
            line: 10,
            metrics: $metrics,
        );

        $symbolPath = $method->getSymbolPath();

        self::assertNotNull($symbolPath);
        self::assertSame('func:App\\Utils::helper', $symbolPath->toCanonical());
    }

    public function testGetSymbolPathForGlobalFunctionWithoutNamespace(): void
    {
        $metrics = (new MetricBag())->with('ccn', 1);

        $method = new MethodWithMetrics(
            namespace: null,
            class: null,
            method: 'globalHelper',
            line: 5,
            metrics: $metrics,
        );

        $symbolPath = $method->getSymbolPath();

        self::assertNotNull($symbolPath);
        self::assertSame('func::globalHelper', $symbolPath->toCanonical());
    }

    public function testGetSymbolPathReturnsNullForClosure(): void
    {
        $metrics = (new MetricBag())->with('ccn', 3);

        $method = new MethodWithMetrics(
            namespace: 'App\\Service',
            class: 'UserService',
            method: '{closure#1}',
            line: 50,
            metrics: $metrics,
        );

        self::assertNull($method->getSymbolPath());
    }

    public function testGetSymbolPathReturnsNullForClosureWithDifferentFormat(): void
    {
        $metrics = new MetricBag();

        $method = new MethodWithMetrics(
            namespace: null,
            class: null,
            method: '{closure}',
            line: 1,
            metrics: $metrics,
        );

        self::assertNull($method->getSymbolPath());
    }

    public function testPropertiesAreAccessible(): void
    {
        $metrics = (new MetricBag())->with('ccn', 7);

        $method = new MethodWithMetrics(
            namespace: 'App\\Domain',
            class: 'Entity',
            method: 'process',
            line: 100,
            metrics: $metrics,
        );

        self::assertSame('App\\Domain', $method->namespace);
        self::assertSame('Entity', $method->class);
        self::assertSame('process', $method->method);
        self::assertSame(100, $method->line);
        self::assertSame(7, $method->metrics->get('ccn'));
    }
}
