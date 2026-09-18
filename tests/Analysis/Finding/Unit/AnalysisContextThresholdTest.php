<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Core\Path\RelativePath;
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

        self::assertNull($context->getThresholdOverride('complexity.ccn', self::subject()));
    }

    /**
     * The key an override is filed under is not part of the match: resolution
     * reads the subject the override itself carries. Filing one under another
     * file's key changes nothing, which is worth stating because the shape of
     * the map says otherwise.
     */
    #[Test]
    public function itMatchesOnTheSubjectAndIgnoresTheMapKey(): void
    {
        $override = self::override('complexity.ccn', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Bar.php' => [
                    $override,
                ],
            ],
        );

        self::assertSame($override, $context->getThresholdOverride('complexity.ccn', self::subject()));
    }

    #[Test]
    public function itGetThresholdOverrideMatchesExact(): void
    {
        $override = self::override('complexity.ccn', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        $result = $context->getThresholdOverride('complexity.ccn', self::subject());

        self::assertSame($override, $result);
    }

    #[Test]
    public function itIgnoresAnOverrideThatOnlyPrefixesTheRuleName(): void
    {
        $override = self::override('complexity', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        self::assertNull($context->getThresholdOverride('complexity.ccn', self::subject()));
    }

    #[Test]
    public function itIgnoresAnOverrideRecordedForAnotherSubject(): void
    {
        $elsewhere = self::override('complexity.ccn', 15, 25, 10, 50, subject: self::otherSubject());
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$elsewhere],
            ],
        );

        self::assertNull($context->getThresholdOverride('complexity.ccn', self::subject()));
        self::assertSame($elsewhere, $context->getThresholdOverride('complexity.ccn', self::otherSubject()));
    }

    #[Test]
    public function itGetThresholdOverrideReturnsNullForNonMatchingRule(): void
    {
        $override = self::override('complexity.ccn', 15, 25, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        self::assertNull($context->getThresholdOverride('coupling.cbo', self::subject()));
    }

    /**
     * Scope first, span second. The two neighbouring cases cannot separate the
     * rules — in both of them the narrower scope also carries the smaller span
     * — so this is the one that says which of the two decides.
     */
    #[Test]
    public function itPrefersTheNarrowerControlScopeOverTheSmallerSpan(): void
    {
        $tightClass = self::override('complexity.ccn', 15, 25, 10, 11, ControlScope::Class_);
        $wideCallable = self::override('complexity.ccn', 30, 50, 1, null);

        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$tightClass, $wideCallable],
            ],
        );

        self::assertSame($wideCallable, $context->getThresholdOverride('complexity.ccn', self::subject()));
    }

    #[Test]
    public function itGetThresholdOverrideReturnsSameSpanFirstMatch(): void
    {
        $override1 = self::override('complexity.ccn', 15, 25, 10, 50);
        $override2 = self::override('complexity.ccn', 20, 30, 10, 50);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override1, $override2],
            ],
        );

        // Same span — first matching override wins
        $result = $context->getThresholdOverride('complexity.ccn', self::subject());
        self::assertSame($override1, $result);
    }

    #[Test]
    public function itMethodLevelOverrideTakesPriorityOverClassLevel(): void
    {
        // Class-level override: line 10-100 (span 90)
        $classOverride = self::override('complexity.ccn', 15, 25, 10, 100, ControlScope::Class_);
        // Method-level override: line 20-40 (span 20) — narrower scope
        $methodOverride = self::override('complexity.ccn', 30, 50, 20, 40);

        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$classOverride, $methodOverride],
            ],
        );

        // Line 30 is within both scopes — callable-level (narrower) wins
        $result = $context->getThresholdOverride('complexity.ccn', self::subject());
        self::assertSame($methodOverride, $result);
    }

    #[Test]
    public function itBoundedOverrideWinsOverUnbounded(): void
    {
        // Unbounded override (null endLine)
        $unbounded = self::override('complexity.ccn', 10, 20, 1, null);
        // Bounded override (narrower scope)
        $bounded = self::override('complexity.ccn', 30, 50, 10, 50);

        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$unbounded, $bounded],
            ],
        );

        // Line 20 is within both — bounded (smaller span) wins
        $result = $context->getThresholdOverride('complexity.ccn', self::subject());
        self::assertSame($bounded, $result);
    }

    #[Test]
    public function itIgnoresAWildcardOverride(): void
    {
        // `@qmx-threshold * 30` reset every rule's threshold on a symbol.
        // The token is no longer a selector, and a threshold has no group form.
        $override = self::override('*', 30, 50, 10, 100);
        $context = new AnalysisContext(
            metrics: self::createStub(MetricRepositoryInterface::class),
            thresholdOverrides: [
                'src/Foo.php' => [$override],
            ],
        );

        self::assertNull($context->getThresholdOverride('complexity.ccn', self::subject()));
        self::assertNull($context->getThresholdOverride('coupling.cbo', self::subject()));
    }
    private static function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')));
    }

    private static function otherSubject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Bar.php')));
    }

    private static function override(
        string $rule,
        int|float|null $warning,
        int|float|null $error,
        int $line,
        ?int $endLine,
        ControlScope $scope = ControlScope::Callable,
        ?MetricSubject $subject = null,
    ): ThresholdOverride {
        return new ThresholdOverride($rule, $warning, $error, $line, $subject ?? self::subject(), $scope, $endLine);
    }
}
