<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use ReflectionObject;
use ReflectionProperty;

#[CoversClass(AnalysisContext::class)]
final class AnalysisContextTest extends TestCase
{
    #[Test]
    public function itDefaultsOptionalEvidenceToAbsent(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertSame($metrics, $context->metrics);
        self::assertNull($context->dependencyGraph);
        self::assertNull($context->namespaceTree);
        self::assertSame([], $context->thresholdOverrides);
    }

    #[Test]
    public function itCarriesDependencyEvidenceWithoutRuntimeConfigurationPayloads(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $dependencyGraph = AdjacencyGraphBuilder::empty();

        $context = new AnalysisContext(metrics: $metrics, dependencyGraph: $dependencyGraph);

        self::assertSame($dependencyGraph, $context->dependencyGraph);
        self::assertNotContains('ruleOptions', $this->publicPropertyNames($context));
        self::assertNotContains('cycles', $this->publicPropertyNames($context));
    }

    #[Test]
    public function itIsReadonly(): void
    {
        self::assertTrue((new ReflectionObject(
            new AnalysisContext(self::createStub(MetricRepositoryInterface::class)),
        ))->isReadOnly());
    }

    /** @return list<string> */
    private function publicPropertyNames(object $object): array
    {
        return array_values(array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            (new ReflectionObject($object))->getProperties(ReflectionProperty::IS_PUBLIC),
        ));
    }
}
