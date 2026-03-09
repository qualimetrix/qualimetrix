<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Coupling\ClassRankOptions;
use AiMessDetector\Rules\Coupling\ClassRankRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $wrongOptions = $this->createStub(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);

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

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_skipsClassesWithoutClassRankMetric(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $symbolPath = SymbolPath::forClass('App', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/SomeClass.php', 10);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn(new MetricBag());

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_noViolationBelowThreshold(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $symbolPath = SymbolPath::forClass('App', 'NormalClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/NormalClass.php', 10);

        $metricBag = (new MetricBag())->with('classRank', 0.01);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function analyze_generatesWarning(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $symbolPath = SymbolPath::forClass('App', 'ImportantClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/ImportantClass.php', 10);

        // 0.03 is above warning (0.02) but below error (0.05)
        $metricBag = (new MetricBag())->with('classRank', 0.03);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('ClassRank is 0.0300', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 0.0200', $violations[0]->message);
        self::assertEqualsWithDelta(0.03, $violations[0]->metricValue, 0.001);
        self::assertSame('coupling.class-rank', $violations[0]->ruleName);
        self::assertSame('coupling.class-rank', $violations[0]->violationCode);
    }

    #[Test]
    public function analyze_generatesError(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $symbolPath = SymbolPath::forClass('App', 'CriticalHub');
        $classInfo = new SymbolInfo($symbolPath, 'src/CriticalHub.php', 10);

        // 0.08 is above error threshold (0.05)
        $metricBag = (new MetricBag())->with('classRank', 0.08);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

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

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('classRank', $classRank);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $violations);
        } else {
            self::assertCount(1, $violations);
            self::assertSame($expectedSeverity, $violations[0]->severity);
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

    // Options tests

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
}
