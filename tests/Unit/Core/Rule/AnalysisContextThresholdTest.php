<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Suppression\ThresholdOverride;

#[CoversClass(AnalysisContext::class)]
final class AnalysisContextThresholdTest extends TestCase
{
    public function testGetThresholdOverrideReturnsNullWhenNoOverrides(): void
    {
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
        );

        self::assertNull($context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 10));
    }

    public function testGetThresholdOverrideReturnsNullForUnknownFile(): void
    {
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Bar.php' => [
                    new ThresholdOverride('complexity.cyclomatic', 15, 25, 10, 50),
                ],
            ],
        );

        self::assertNull($context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 10));
    }

    public function testGetThresholdOverrideMatchesExact(): void
    {
        $override = new ThresholdOverride('complexity.cyclomatic', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        $result = $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 20);

        self::assertSame($override, $result);
    }

    public function testGetThresholdOverrideMatchesPrefix(): void
    {
        $override = new ThresholdOverride('complexity', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        $result = $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 20);

        self::assertSame($override, $result);
    }

    public function testGetThresholdOverrideRespectsLineScope(): void
    {
        $override = new ThresholdOverride('complexity.cyclomatic', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        // Inside scope
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 10));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 50));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 30));

        // Outside scope
        self::assertNull($context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 9));
        self::assertNull($context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 51));
    }

    public function testGetThresholdOverrideReturnsNullForNonMatchingRule(): void
    {
        $override = new ThresholdOverride('complexity.cyclomatic', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        self::assertNull($context->getThresholdOverride('coupling.cbo', 'src/Foo.php', 20));
    }

    public function testGetThresholdOverrideWithNullEndLine(): void
    {
        $override = new ThresholdOverride('complexity.cyclomatic', 15, 25, 10, null);
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        // With null endLine, any line >= startLine matches
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 10));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 100));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 1000));
    }

    public function testGetThresholdOverrideReturnsSameSpanFirstMatch(): void
    {
        $override1 = new ThresholdOverride('complexity', 15, 25, 10, 50);
        $override2 = new ThresholdOverride('complexity.cyclomatic', 20, 30, 10, 50);
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override1, $override2],
            ],
        );

        // Same span — first matching override wins
        $result = $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 20);
        self::assertSame($override1, $result);
    }

    public function testMethodLevelOverrideTakesPriorityOverClassLevel(): void
    {
        // Class-level override: line 10-100 (span 90)
        $classOverride = new ThresholdOverride('complexity.cyclomatic', 15, 25, 10, 100);
        // Method-level override: line 20-40 (span 20) — narrower scope
        $methodOverride = new ThresholdOverride('complexity.cyclomatic', 30, 50, 20, 40);

        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$classOverride, $methodOverride],
            ],
        );

        // Line 30 is within both scopes — method-level (narrower) wins
        $result = $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 30);
        self::assertSame($methodOverride, $result);

        // Line 50 is within class scope only — class-level wins
        $result = $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 50);
        self::assertSame($classOverride, $result);
    }

    public function testBoundedOverrideWinsOverUnbounded(): void
    {
        // Unbounded override (null endLine)
        $unbounded = new ThresholdOverride('complexity.cyclomatic', 10, 20, 1, null);
        // Bounded override (narrower scope)
        $bounded = new ThresholdOverride('complexity.cyclomatic', 30, 50, 10, 50);

        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$unbounded, $bounded],
            ],
        );

        // Line 20 is within both — bounded (smaller span) wins
        $result = $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 20);
        self::assertSame($bounded, $result);

        // Line 60 is only within unbounded scope
        $result = $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 60);
        self::assertSame($unbounded, $result);
    }

    public function testGetThresholdOverrideWithWildcard(): void
    {
        $override = new ThresholdOverride('*', 30, 50, 10, 100);
        $context = new AnalysisContext(
            metrics: $this->createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', 'src/Foo.php', 20));
        self::assertSame($override, $context->getThresholdOverride('coupling.cbo', 'src/Foo.php', 20));
    }
}
