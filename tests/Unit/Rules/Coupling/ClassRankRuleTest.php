<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Coupling\ClassRankOptions;
use Qualimetrix\Rules\Coupling\ClassRankRule;

#[CoversClass(ClassRankRule::class)]
#[CoversClass(ClassRankOptions::class)]
final class ClassRankRuleTest extends TestCase
{
    #[Test]
    public function getName_returnsCorrectName(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        self::assertSame('coupling.class-rank', $rule->getName());
    }

    #[Test]
    public function getDescription_returnsNonEmptyString(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        self::assertNotEmpty($rule->getDescription());
    }

    #[Test]
    public function getCategory_returnsCoupling(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    #[Test]
    public function requires_returnsClassRank(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        self::assertSame(['classRank'], $rule->requires());
    }

    #[Test]
    public function getOptionsClass_returnsClassRankOptions(): void
    {
        self::assertSame(ClassRankOptions::class, ClassRankRule::getOptionsClass());
    }

    #[Test]
    public function throwsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = $this->createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        new ClassRankRule($wrongOptions);
    }

    #[Test]
    public function analyze_returnsEmptyWhenDisabled(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_returnsEmptyWhenNoClasses(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_skipsClassesWithoutClassRankMetric(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $classes = $this->createDummyClasses(100, 'src/SomeClass.php', 10);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturn(new MetricBag());

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_noViolationBelowThreshold(): void
    {
        // With 100 classes, scale factor = 1.0, so thresholds are unchanged
        $rule = new ClassRankRule(new ClassRankOptions());

        $classes = $this->createDummyClasses(100);

        $metricBag = (new MetricBag())->with('classRank', 0.01);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // All 100 classes have rank 0.01, below warning threshold 0.02
        self::assertCount(0, $violations);
    }

    #[Test]
    public function analyze_generatesWarning(): void
    {
        // With 100 classes, scale factor = 1.0, thresholds unchanged
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'ImportantClass');
        $targetInfo = new SymbolInfo($targetPath, 'src/ImportantClass.php', 10);

        // 0.03 is above warning (0.02) but below error (0.05)
        $targetBag = (new MetricBag())->with('classRank', 0.03);
        $normalBag = (new MetricBag())->with('classRank', 0.005);

        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Only the target class exceeds the warning threshold
        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('ClassRank is 0.0300', $violations[0]->message);
        self::assertStringContainsString('scaled for 100 classes', $violations[0]->message);
        self::assertEqualsWithDelta(0.03, $violations[0]->metricValue, 0.001);
        self::assertSame('coupling.class-rank', $violations[0]->ruleName);
        self::assertSame('coupling.class-rank', $violations[0]->violationCode);
    }

    #[Test]
    public function analyze_generatesError(): void
    {
        // With 100 classes, scale factor = 1.0, thresholds unchanged
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'CriticalHub');
        $targetInfo = new SymbolInfo($targetPath, 'src/CriticalHub.php', 10);

        // 0.08 is above error threshold (0.05)
        $targetBag = (new MetricBag())->with('classRank', 0.08);
        $normalBag = (new MetricBag())->with('classRank', 0.005);

        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertEqualsWithDelta(0.08, $violations[0]->metricValue, 0.001);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        float $classRank,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new ClassRankRule(new ClassRankOptions(
            warning: $warning,
            error: $error,
        ));

        $targetPath = SymbolPath::forClass('App', 'TestClass');
        $targetInfo = new SymbolInfo($targetPath, 'test.php', 1);

        $targetBag = (new MetricBag())->with('classRank', $classRank);
        $normalBag = (new MetricBag())->with('classRank', 0.001);

        // Use 100 classes so scale factor = 1.0
        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Filter to just the target class violations
        $targetViolations = array_values(array_filter(
            $violations,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        if ($expectedSeverity === null) {
            self::assertCount(0, $targetViolations);
        } else {
            self::assertCount(1, $targetViolations);
            self::assertSame($expectedSeverity, $targetViolations[0]->severity);
        }
    }

    /**
     * @return iterable<string, array{float, float, float, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        yield 'below warning' => [0.01, 0.02, 0.05, null];
        yield 'at warning' => [0.02, 0.02, 0.05, Severity::Warning];
        yield 'between warning and error' => [0.03, 0.02, 0.05, Severity::Warning];
        yield 'at error' => [0.05, 0.02, 0.05, Severity::Error];
        yield 'above error' => [0.10, 0.02, 0.05, Severity::Error];
    }

    // --- Threshold scaling tests ---

    #[Test]
    public function computeScaleFactor_at100Classes_returnsOne(): void
    {
        self::assertEqualsWithDelta(1.0, ClassRankRule::computeScaleFactor(100), 0.001);
    }

    #[Test]
    public function computeScaleFactor_at1600Classes_returnsFour(): void
    {
        // sqrt(1600/100) = sqrt(16) = 4
        self::assertEqualsWithDelta(4.0, ClassRankRule::computeScaleFactor(1600), 0.001);
    }

    #[Test]
    public function computeScaleFactor_at25Classes_returnsHalf(): void
    {
        // sqrt(25/100) = sqrt(0.25) = 0.5
        self::assertEqualsWithDelta(0.5, ClassRankRule::computeScaleFactor(25), 0.001);
    }

    #[Test]
    public function computeScaleFactor_atZeroClasses_returnsOne(): void
    {
        self::assertEqualsWithDelta(1.0, ClassRankRule::computeScaleFactor(0), 0.001);
    }

    #[Test]
    public function analyze_largeProject_lowersThresholds(): void
    {
        // With 400 classes: scale factor = sqrt(400/100) = 2.0
        // Effective warning = 0.02 / 2 = 0.01, effective error = 0.05 / 2 = 0.025
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'Hub');
        $targetInfo = new SymbolInfo($targetPath, 'src/Hub.php', 10);

        // 0.015 would be below unscaled warning (0.02), but above scaled warning (0.01)
        $targetBag = (new MetricBag())->with('classRank', 0.015);
        $normalBag = (new MetricBag())->with('classRank', 0.001);

        $classes = $this->createDummyClasses(399);
        $classes[] = $targetInfo;

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        $targetViolations = array_values(array_filter(
            $violations,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(1, $targetViolations);
        self::assertSame(Severity::Warning, $targetViolations[0]->severity);
    }

    #[Test]
    public function analyze_smallProject_raisesThresholds(): void
    {
        // With 25 classes: scale factor = sqrt(25/100) = 0.5
        // Effective warning = 0.02 / 0.5 = 0.04, effective error = 0.05 / 0.5 = 0.10
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'SmallHub');
        $targetInfo = new SymbolInfo($targetPath, 'src/SmallHub.php', 10);

        // 0.03 would normally be a warning with default thresholds,
        // but with 25 classes, scaled warning = 0.04, so no violation
        $targetBag = (new MetricBag())->with('classRank', 0.03);
        $normalBag = (new MetricBag())->with('classRank', 0.001);

        $classes = $this->createDummyClasses(24);
        $classes[] = $targetInfo;

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        $targetViolations = array_values(array_filter(
            $violations,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(0, $targetViolations);
    }

    #[Test]
    public function analyze_largeProject_errorAtLowerRank(): void
    {
        // With 1600 classes: scale factor = 4.0
        // Effective error = 0.05 / 4 = 0.0125
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'MegaHub');
        $targetInfo = new SymbolInfo($targetPath, 'src/MegaHub.php', 10);

        // 0.02 would normally just be a warning, but with 1600 classes it's an error
        $targetBag = (new MetricBag())->with('classRank', 0.02);
        $normalBag = (new MetricBag())->with('classRank', 0.0001);

        $classes = $this->createDummyClasses(1599);
        $classes[] = $targetInfo;

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        $targetViolations = array_values(array_filter(
            $violations,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(1, $targetViolations);
        self::assertSame(Severity::Error, $targetViolations[0]->severity);
    }

    #[Test]
    public function analyze_messageIncludesClassCount(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'Hub');
        $targetInfo = new SymbolInfo($targetPath, 'src/Hub.php', 10);

        $targetBag = (new MetricBag())->with('classRank', 0.03);
        $normalBag = (new MetricBag())->with('classRank', 0.001);

        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        $targetViolations = array_values(array_filter(
            $violations,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(1, $targetViolations);
        self::assertStringContainsString('scaled for 100 classes', $targetViolations[0]->message);
    }

    // --- Options tests ---

    #[Test]
    public function options_defaults(): void
    {
        $options = new ClassRankOptions();

        self::assertTrue($options->isEnabled());
        self::assertEqualsWithDelta(0.02, $options->warning, 0.001);
        self::assertEqualsWithDelta(0.05, $options->error, 0.001);
    }

    #[Test]
    public function options_fromEmptyArray_disablesRule(): void
    {
        $options = ClassRankOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function options_fromArray_customValues(): void
    {
        $options = ClassRankOptions::fromArray([
            'enabled' => true,
            'warning' => 0.03,
            'error' => 0.08,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertEqualsWithDelta(0.03, $options->warning, 0.001);
        self::assertEqualsWithDelta(0.08, $options->error, 0.001);
    }

    #[Test]
    public function options_fromArray_disabledExplicitly(): void
    {
        $options = ClassRankOptions::fromArray([
            'enabled' => false,
        ]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function options_getSeverity_returnsNullBelowThreshold(): void
    {
        $options = new ClassRankOptions();

        self::assertNull($options->getSeverity(0.01));
    }

    #[Test]
    public function options_getSeverity_returnsWarning(): void
    {
        $options = new ClassRankOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(0.03));
    }

    #[Test]
    public function options_getSeverity_returnsError(): void
    {
        $options = new ClassRankOptions();

        self::assertSame(Severity::Error, $options->getSeverity(0.08));
    }

    #[Test]
    public function getCliAliases_returnsExpectedAliases(): void
    {
        $aliases = ClassRankRule::getCliAliases();

        self::assertArrayHasKey('class-rank-warning', $aliases);
        self::assertArrayHasKey('class-rank-error', $aliases);
        self::assertSame('warning', $aliases['class-rank-warning']);
        self::assertSame('error', $aliases['class-rank-error']);
    }

    /**
     * Creates N dummy SymbolInfo instances for class symbols.
     *
     * @return list<SymbolInfo>
     */
    private function createDummyClasses(int $count, string $file = 'src/Dummy.php', int $line = 1): array
    {
        $classes = [];
        for ($i = 0; $i < $count; $i++) {
            $path = SymbolPath::forClass('App\\Dummy', 'DummyClass' . $i);
            $classes[] = new SymbolInfo($path, $file, $line);
        }

        return $classes;
    }
}
