<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Rules\Coupling\ClassCouplingOptions;
use AiMessDetector\Rules\Coupling\CouplingOptions;
use AiMessDetector\Rules\Coupling\CouplingRule;
use AiMessDetector\Rules\Coupling\NamespaceCouplingOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CouplingRule::class)]
#[CoversClass(CouplingOptions::class)]
#[CoversClass(ClassCouplingOptions::class)]
#[CoversClass(NamespaceCouplingOptions::class)]
final class CouplingRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        self::assertSame('coupling', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        self::assertSame(
            'Checks instability (coupling) at class and namespace levels',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        self::assertSame(['instability', 'ca', 'ce', 'cbo'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            CouplingOptions::class,
            CouplingRule::getOptionsClass(),
        );
    }

    public function testGetSupportedLevels(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $rule->getSupportedLevels());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame([
            'coupling-class-warning' => 'class.max_instability_warning',
            'coupling-class-error' => 'class.max_instability_error',
            'coupling-ns-warning' => 'namespace.max_instability_warning',
            'coupling-ns-error' => 'namespace.max_instability_error',
            'cbo-class-warning' => 'class.cbo_warning_threshold',
            'cbo-class-error' => 'class.cbo_error_threshold',
            'cbo-ns-warning' => 'namespace.cbo_warning_threshold',
            'cbo-ns-error' => 'namespace.cbo_error_threshold',
        ], CouplingRule::getCliAliases());
    }

    // Class-level tests

    public function testAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
    {
        $rule = new CouplingRule(
            new CouplingOptions(
                class: new ClassCouplingOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassReturnsEmptyWhenNoClasses(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassSkipsWhenNoInstabilityMetric(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = new MetricBag();

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassGeneratesWarning(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // 0.85 is above warning (0.8), below error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.85)
            ->with('ca', 2)
            ->with('ce', 12);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Class has instability of 0.85 (Ca=2, Ce=12)', $violations[0]->message);
        self::assertSame(0.85, $violations[0]->metricValue);
        self::assertSame('coupling', $violations[0]->ruleName);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    public function testAnalyzeLevelClassGeneratesError(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // 0.97 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.97)
            ->with('ca', 1)
            ->with('ce', 32);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.97, $violations[0]->metricValue);
    }

    // Namespace-level tests

    public function testAnalyzeLevelNamespaceReturnsEmptyWhenDisabled(): void
    {
        $rule = new CouplingRule(
            new CouplingOptions(
                namespace: new NamespaceCouplingOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Namespace_, $context));
    }

    public function testAnalyzeLevelNamespaceGeneratesWarning(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', 0);

        // 0.88 is above warning (0.8), below error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.88)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Namespace has instability of 0.88', $violations[0]->message);
        self::assertSame(RuleLevel::Namespace_, $violations[0]->level);
    }

    public function testAnalyzeLevelNamespaceGeneratesError(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', 0);

        // 0.98 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.98, $violations[0]->metricValue);
    }

    // Legacy analyze() tests

    public function testAnalyzeCallsBothLevels(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, 'src/Service/UserService.php', 10);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($nsPath, 'src/Service', 0);

        $classBag = (new MetricBag())
            ->with('instability', 0.85)
            ->with('ca', 2)
            ->with('ce', 12);
        $nsBag = (new MetricBag())
            ->with('instability', 0.88)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => match ($type) {
                SymbolType::Class_ => [$classInfo],
                SymbolType::Namespace_ => [$nsInfo],
                default => [],
            });
        $repository->method('get')
            ->willReturnCallback(fn(SymbolPath $path) => match ($path) {
                $classPath => $classBag,
                $nsPath => $nsBag,
                default => new MetricBag(),
            });

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
        self::assertSame(RuleLevel::Namespace_, $violations[1]->level);
    }

    // Options tests

    public function testClassOptionsFromArray(): void
    {
        $options = ClassCouplingOptions::fromArray([
            'enabled' => false,
            'max_instability_warning' => 0.7,
            'max_instability_error' => 0.9,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.7, $options->maxInstabilityWarning);
        self::assertSame(0.9, $options->maxInstabilityError);
    }

    public function testClassOptionsFromEmptyArray(): void
    {
        $options = ClassCouplingOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    public function testNamespaceOptionsFromArray(): void
    {
        $options = NamespaceCouplingOptions::fromArray([
            'enabled' => false,
            'max_instability_warning' => 0.75,
            'max_instability_error' => 0.92,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.75, $options->maxInstabilityWarning);
        self::assertSame(0.92, $options->maxInstabilityError);
    }

    public function testCouplingOptionsFromHierarchicalArray(): void
    {
        $options = CouplingOptions::fromArray([
            'class' => [
                'max_instability_warning' => 0.7,
                'max_instability_error' => 0.9,
            ],
            'namespace' => [
                'max_instability_warning' => 0.75,
                'max_instability_error' => 0.92,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->class->isEnabled());
        self::assertSame(0.7, $options->class->maxInstabilityWarning);
        self::assertTrue($options->namespace->isEnabled());
        self::assertSame(0.75, $options->namespace->maxInstabilityWarning);
    }

    public function testCouplingOptionsFromLegacyArray(): void
    {
        $options = CouplingOptions::fromArray([
            'enabled' => true,
            'maxInstabilityWarning' => 0.7,
            'maxInstabilityError' => 0.9,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->class->isEnabled());
        self::assertSame(0.7, $options->class->maxInstabilityWarning);
        self::assertSame(0.9, $options->class->maxInstabilityError);
        // Legacy format disables namespace level
        self::assertFalse($options->namespace->isEnabled());
    }

    public function testCouplingOptionsForLevel(): void
    {
        $options = new CouplingOptions();

        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(RuleLevel::Namespace_));
    }

    public function testCouplingOptionsForLevelThrowsForUnsupportedLevel(): void
    {
        $options = new CouplingOptions();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Level method is not supported by CouplingRule');

        $options->forLevel(RuleLevel::Method);
    }

    public function testCouplingOptionsIsLevelEnabled(): void
    {
        $options = new CouplingOptions(
            class: new ClassCouplingOptions(enabled: true),
            namespace: new NamespaceCouplingOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Class_));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Namespace_));
    }

    public function testCouplingOptionsGetSupportedLevels(): void
    {
        $options = new CouplingOptions();

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $options->getSupportedLevels());
    }

    #[DataProvider('instabilityThresholdDataProvider')]
    public function testInstabilityThresholdBoundaries(
        float $instability,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new CouplingRule(
            new CouplingOptions(
                class: new ClassCouplingOptions(
                    maxInstabilityWarning: $warning,
                    maxInstabilityError: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())
            ->with('instability', $instability)
            ->with('ca', 5)
            ->with('ce', 10);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

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
    public static function instabilityThresholdDataProvider(): iterable
    {
        yield 'below warning threshold' => [0.79, 0.8, 0.95, null];
        yield 'at warning threshold' => [0.8, 0.8, 0.95, Severity::Warning];
        yield 'above warning, below error' => [0.9, 0.8, 0.95, Severity::Warning];
        yield 'at error threshold' => [0.95, 0.8, 0.95, Severity::Error];
        yield 'above error threshold' => [1.0, 0.8, 0.95, Severity::Error];
    }

    public function testConstructorThrowsForInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        $invalidOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
        new CouplingRule($invalidOptions);
    }

    // CBO tests

    public function testAnalyzeLevelClassCboNoViolationBelowThreshold(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 10, below warning threshold (14)
        $metricBag = (new MetricBag())
            ->with('cbo', 10)
            ->with('ca', 5)
            ->with('ce', 5)
            ->with('instability', 0.5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        // No CBO violation, but may have instability violations
        $cboViolations = array_filter($violations, fn($v) => str_contains($v->message, 'CBO'));
        self::assertCount(0, $cboViolations);
    }

    public function testAnalyzeLevelClassCboGeneratesWarning(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 18, above warning (14), below error (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 18)
            ->with('ca', 8)
            ->with('ce', 10)
            ->with('instability', 0.5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        // Find CBO violation
        $cboViolations = array_filter($violations, fn($v) => str_contains($v->message, 'CBO'));
        self::assertCount(1, $cboViolations);

        $cboViolation = array_values($cboViolations)[0];
        self::assertSame(Severity::Warning, $cboViolation->severity);
        self::assertStringContainsString('CBO (Coupling Between Objects) of 18', $cboViolation->message);
        self::assertStringContainsString('Ca=8, Ce=10', $cboViolation->message);
        self::assertSame(18.0, $cboViolation->metricValue);
    }

    public function testAnalyzeLevelClassCboGeneratesError(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 25, above error threshold (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15)
            ->with('instability', 0.6);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        // Find CBO violation
        $cboViolations = array_filter($violations, fn($v) => str_contains($v->message, 'CBO'));
        self::assertCount(1, $cboViolations);

        $cboViolation = array_values($cboViolations)[0];
        self::assertSame(Severity::Error, $cboViolation->severity);
        self::assertStringContainsString('CBO (Coupling Between Objects) of 25', $cboViolation->message);
        self::assertSame(25.0, $cboViolation->metricValue);
    }

    public function testAnalyzeLevelClassCboCustomThresholds(): void
    {
        $rule = new CouplingRule(
            new CouplingOptions(
                class: new ClassCouplingOptions(
                    cboWarningThreshold: 10,
                    cboErrorThreshold: 15,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 12, above custom warning (10), below custom error (15)
        $metricBag = (new MetricBag())
            ->with('cbo', 12)
            ->with('ca', 6)
            ->with('ce', 6)
            ->with('instability', 0.5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        $cboViolations = array_filter($violations, fn($v) => str_contains($v->message, 'CBO'));
        self::assertCount(1, $cboViolations);

        $cboViolation = array_values($cboViolations)[0];
        self::assertSame(Severity::Warning, $cboViolation->severity);
        self::assertStringContainsString('exceeds threshold of 10', $cboViolation->message);
    }

    public function testAnalyzeLevelNamespaceCboGeneratesWarning(): void
    {
        $rule = new CouplingRule(new CouplingOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', 0);

        // CBO = 16
        $metricBag = (new MetricBag())
            ->with('cbo', 16)
            ->with('ca', 6)
            ->with('ce', 10)
            ->with('instability', 0.625);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        $cboViolations = array_filter($violations, fn($v) => str_contains($v->message, 'CBO'));
        self::assertCount(1, $cboViolations);

        $cboViolation = array_values($cboViolations)[0];
        self::assertSame(Severity::Warning, $cboViolation->severity);
        self::assertStringContainsString('Namespace has CBO', $cboViolation->message);
        self::assertSame(RuleLevel::Namespace_, $cboViolation->level);
    }

    public function testClassOptionsFromArrayIncludesCboThresholds(): void
    {
        $options = ClassCouplingOptions::fromArray([
            'enabled' => true,
            'max_instability_warning' => 0.7,
            'max_instability_error' => 0.9,
            'cbo_warning_threshold' => 12,
            'cbo_error_threshold' => 18,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(0.7, $options->maxInstabilityWarning);
        self::assertSame(0.9, $options->maxInstabilityError);
        self::assertSame(12, $options->cboWarningThreshold);
        self::assertSame(18, $options->cboErrorThreshold);
    }

    public function testNamespaceOptionsFromArrayIncludesCboThresholds(): void
    {
        $options = NamespaceCouplingOptions::fromArray([
            'enabled' => true,
            'cbo_warning_threshold' => 10,
            'cbo_error_threshold' => 16,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(10, $options->cboWarningThreshold);
        self::assertSame(16, $options->cboErrorThreshold);
    }
}
