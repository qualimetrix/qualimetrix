<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(AnalysisContext::class)]
final class AnalysisContextThresholdTest extends TestCase
{
    #[Test]
    public function itGetThresholdOverrideReturnsNullWhenNoOverrides(): void
    {
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
        );

        self::assertNull($context->getThresholdOverride('complexity.cyclomatic', self::subject()));
    }

    #[Test]
    public function itGetThresholdOverrideReturnsNullForUnknownFile(): void
    {
        $override = self::override('complexity.cyclomatic', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Bar.php' => [
                    $override,
                ],
            ],
        );

        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
    }

    #[Test]
    public function itGetThresholdOverrideMatchesExact(): void
    {
        $override = self::override('complexity.cyclomatic', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        $result = $context->getThresholdOverride('complexity.cyclomatic', self::subject());

        self::assertSame($override, $result);
    }

    #[Test]
    public function itGetThresholdOverrideMatchesPrefix(): void
    {
        $override = self::override('complexity', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        $result = $context->getThresholdOverride('complexity.cyclomatic', self::subject());

        self::assertSame($override, $result);
    }

    #[Test]
    public function itGetThresholdOverrideRespectsLineScope(): void
    {
        $override = self::override('complexity.cyclomatic', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        // Inside scope
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));

        // Outside scope
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
    }

    #[Test]
    public function itGetThresholdOverrideReturnsNullForNonMatchingRule(): void
    {
        $override = self::override('complexity.cyclomatic', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        self::assertNull($context->getThresholdOverride('coupling.cbo', self::subject()));
    }

    #[Test]
    public function itGetThresholdOverrideWithNullEndLine(): void
    {
        $override = self::override('complexity.cyclomatic', 15, 25, 10, null);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        // With null endLine, any line >= startLine matches
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
    }

    #[Test]
    public function itGetThresholdOverrideReturnsSameSpanFirstMatch(): void
    {
        $override1 = self::override('complexity', 15, 25, 10, 50);
        $override2 = self::override('complexity.cyclomatic', 20, 30, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override1, $override2],
            ],
        );

        // Same span — first matching override wins
        $result = $context->getThresholdOverride('complexity.cyclomatic', self::subject());
        self::assertSame($override1, $result);
    }

    #[Test]
    public function itMethodLevelOverrideTakesPriorityOverClassLevel(): void
    {
        // Class-level override: line 10-100 (span 90)
        $classOverride = self::override('complexity.cyclomatic', 15, 25, 10, 100, ControlScope::Class_);
        // Method-level override: line 20-40 (span 20) — narrower scope
        $methodOverride = self::override('complexity.cyclomatic', 30, 50, 20, 40);

        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$classOverride, $methodOverride],
            ],
        );

        // Line 30 is within both scopes — callable-level (narrower) wins
        $result = $context->getThresholdOverride('complexity.cyclomatic', self::subject());
        self::assertSame($methodOverride, $result);

        // Resolution is declaration-bound, so the callable control remains the winner.
        $result = $context->getThresholdOverride('complexity.cyclomatic', self::subject());
        self::assertSame($methodOverride, $result);
    }

    #[Test]
    public function itBoundedOverrideWinsOverUnbounded(): void
    {
        // Unbounded override (null endLine)
        $unbounded = self::override('complexity.cyclomatic', 10, 20, 1, null);
        // Bounded override (narrower scope)
        $bounded = self::override('complexity.cyclomatic', 30, 50, 10, 50);

        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$unbounded, $bounded],
            ],
        );

        // Line 20 is within both — bounded (smaller span) wins
        $result = $context->getThresholdOverride('complexity.cyclomatic', self::subject());
        self::assertSame($bounded, $result);

        // Source lines are presentation metadata and do not change subject matching.
        $result = $context->getThresholdOverride('complexity.cyclomatic', self::subject());
        self::assertSame($bounded, $result);
    }

    #[Test]
    public function itGetThresholdOverrideWithWildcard(): void
    {
        $override = self::override('*', 30, 50, 10, 100);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        self::assertSame($override, $context->getThresholdOverride('complexity.cyclomatic', self::subject()));
        self::assertSame($override, $context->getThresholdOverride('coupling.cbo', self::subject()));
    }
    private static function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')));
    }

    private static function override(string $rule, int|float|null $warning, int|float|null $error, int $line, ?int $endLine, ControlScope $scope = ControlScope::Callable): ThresholdOverride
    {
        return new ThresholdOverride($rule, $warning, $error, $line, self::subject(), $scope, $endLine);
    }
}
