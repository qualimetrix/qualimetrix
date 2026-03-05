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
use AiMessDetector\Rules\Coupling\CboOptions;
use AiMessDetector\Rules\Coupling\CboRule;
use AiMessDetector\Rules\Coupling\ClassCboOptions;
use AiMessDetector\Rules\Coupling\NamespaceCboOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CboRule::class)]
#[CoversClass(CboOptions::class)]
#[CoversClass(ClassCboOptions::class)]
#[CoversClass(NamespaceCboOptions::class)]
final class CboRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame('coupling.cbo', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(
            'Checks CBO (Coupling Between Objects) at class and namespace levels',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(['cbo', 'ca', 'ce'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            CboOptions::class,
            CboRule::getOptionsClass(),
        );
    }

    public function testGetSupportedLevels(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $rule->getSupportedLevels());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame([
            'cbo-class-warning' => 'class.warning',
            'cbo-class-error' => 'class.error',
            'cbo-ns-warning' => 'namespace.warning',
            'cbo-ns-error' => 'namespace.error',
        ], CboRule::getCliAliases());
    }

    public function testConstructorThrowsForInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        $invalidOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
        new CboRule($invalidOptions);
    }

    // Class-level tests

    public function testAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
    {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassReturnsEmptyWhenNoClasses(): void
    {
        $rule = new CboRule(new CboOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassSkipsWhenNoCboMetric(): void
    {
        $rule = new CboRule(new CboOptions());

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

    public function testAnalyzeLevelClassNoViolationBelowThreshold(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 10, below warning threshold (14)
        $metricBag = (new MetricBag())
            ->with('cbo', 10)
            ->with('ca', 5)
            ->with('ce', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(0, $violations);
    }

    public function testAnalyzeLevelClassCboGeneratesWarning(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 18, above warning (14), below error (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 18)
            ->with('ca', 8)
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

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('CBO (Coupling Between Objects) is 18 (Ca=8, Ce=10), exceeds threshold of 14', $violations[0]->message);
        self::assertSame(18.0, $violations[0]->metricValue);
        self::assertSame('coupling.cbo', $violations[0]->ruleName);
        self::assertSame('coupling.cbo.class', $violations[0]->violationCode);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    public function testAnalyzeLevelClassCboGeneratesError(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 25, above error threshold (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15);

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
        self::assertStringContainsString('exceeds threshold of 20', $violations[0]->message);
        self::assertSame(25.0, $violations[0]->metricValue);
    }

    public function testAnalyzeLevelClassCboCustomThresholds(): void
    {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(
                    warning: 10,
                    error: 15,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 12, above custom warning (10), below custom error (15)
        $metricBag = (new MetricBag())
            ->with('cbo', 12)
            ->with('ca', 6)
            ->with('ce', 6);

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
        self::assertStringContainsString('exceeds threshold of 10', $violations[0]->message);
    }

    // Namespace-level tests

    public function testAnalyzeLevelNamespaceReturnsEmptyWhenDisabled(): void
    {
        $rule = new CboRule(
            new CboOptions(
                namespace: new NamespaceCboOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Namespace_, $context));
    }

    public function testAnalyzeLevelNamespaceCboGeneratesWarning(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // CBO = 16
        $metricBag = (new MetricBag())
            ->with('cbo', 16)
            ->with('ca', 6)
            ->with('ce', 10)
            ->with('classCount.sum', 5);

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
        self::assertStringContainsString('CBO (Coupling Between Objects) is 16 (Ca=6, Ce=10), exceeds threshold of 14', $violations[0]->message);
        self::assertSame('coupling.cbo.namespace', $violations[0]->violationCode);
        self::assertSame(RuleLevel::Namespace_, $violations[0]->level);
    }

    public function testAnalyzeLevelNamespaceCboGeneratesError(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // CBO = 25
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15)
            ->with('classCount.sum', 5);

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
        self::assertSame(25.0, $violations[0]->metricValue);
    }

    // Namespace minClassCount tests

    public function testAnalyzeLevelNamespaceSkipsWhenBelowMinClassCount(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // classCount.sum = 1, below default minClassCount (3)
        $metricBag = (new MetricBag())
            ->with('cbo', 50)
            ->with('ca', 20)
            ->with('ce', 30)
            ->with('classCount.sum', 1);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(0, $violations);
    }

    // Legacy analyze() tests

    public function testAnalyzeCallsBothLevels(): void
    {
        $rule = new CboRule(new CboOptions());

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, 'src/Service/UserService.php', 10);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($nsPath, 'src/Service', null);

        $classBag = (new MetricBag())
            ->with('cbo', 18)
            ->with('ca', 8)
            ->with('ce', 10);
        $nsBag = (new MetricBag())
            ->with('cbo', 16)
            ->with('ca', 6)
            ->with('ce', 10)
            ->with('classCount.sum', 5);

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
        $options = ClassCboOptions::fromArray([
            'enabled' => false,
            'warning' => 10,
            'error' => 15,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(15, $options->error);
    }

    public function testClassOptionsFromEmptyArray(): void
    {
        $options = ClassCboOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    public function testNamespaceOptionsFromArray(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'enabled' => false,
            'warning' => 10,
            'error' => 16,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(16, $options->error);
    }

    public function testCboOptionsFromHierarchicalArray(): void
    {
        $options = CboOptions::fromArray([
            'class' => [
                'warning' => 10,
                'error' => 15,
            ],
            'namespace' => [
                'warning' => 12,
                'error' => 18,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->class->isEnabled());
        self::assertSame(10, $options->class->warning);
        self::assertTrue($options->namespace->isEnabled());
        self::assertSame(12, $options->namespace->warning);
    }

    public function testCboOptionsForLevel(): void
    {
        $options = new CboOptions();

        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(RuleLevel::Namespace_));
    }

    public function testCboOptionsForLevelThrowsForUnsupportedLevel(): void
    {
        $options = new CboOptions();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Level method is not supported by CboRule');

        $options->forLevel(RuleLevel::Method);
    }

    public function testCboOptionsIsLevelEnabled(): void
    {
        $options = new CboOptions(
            class: new ClassCboOptions(enabled: true),
            namespace: new NamespaceCboOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Class_));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Namespace_));
    }

    public function testCboOptionsGetSupportedLevels(): void
    {
        $options = new CboOptions();

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $options->getSupportedLevels());
    }

    public function testNamespaceOptionsFromArrayIncludesMinClassCount(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'min_class_count' => 5,
        ]);

        self::assertSame(5, $options->minClassCount);
    }

    public function testNamespaceOptionsFromArrayMinClassCountDefaultsToThree(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'enabled' => true,
        ]);

        self::assertSame(3, $options->minClassCount);
    }

    public function testNamespaceOptionsFromArrayMinClassCountCamelCaseAlias(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'minClassCount' => 7,
        ]);

        self::assertSame(7, $options->minClassCount);
    }

    #[DataProvider('cboThresholdDataProvider')]
    public function testCboThresholdBoundaries(
        int $cbo,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(
                    warning: $warning,
                    error: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())
            ->with('cbo', $cbo)
            ->with('ca', 5)
            ->with('ce', $cbo - 5);

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
     * @return iterable<string, array{int, int, int, ?Severity}>
     */
    public static function cboThresholdDataProvider(): iterable
    {
        yield 'below warning threshold' => [13, 14, 20, null];
        yield 'at warning threshold (not exceeded)' => [14, 14, 20, null];
        yield 'above warning, below error' => [18, 14, 20, Severity::Warning];
        yield 'at error threshold (not exceeded)' => [20, 14, 20, Severity::Warning];
        yield 'above error threshold' => [25, 14, 20, Severity::Error];
    }
}
