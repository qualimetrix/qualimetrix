<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Size;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Rules\Size\ClassSizeOptions;
use AiMessDetector\Rules\Size\NamespaceLevelOptions;
use AiMessDetector\Rules\Size\SizeOptions;
use AiMessDetector\Rules\Size\SizeRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SizeRule::class)]
#[CoversClass(SizeOptions::class)]
#[CoversClass(ClassSizeOptions::class)]
#[CoversClass(NamespaceLevelOptions::class)]
final class SizeRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new SizeRule(new SizeOptions());

        self::assertSame('size', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new SizeRule(new SizeOptions());

        self::assertSame(
            'Checks size at class and namespace levels',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new SizeRule(new SizeOptions());

        self::assertSame(RuleCategory::Size, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new SizeRule(new SizeOptions());

        self::assertSame(['classCount', 'methodCount'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            SizeOptions::class,
            SizeRule::getOptionsClass(),
        );
    }

    public function testGetSupportedLevels(): void
    {
        $rule = new SizeRule(new SizeOptions());

        self::assertSame(
            [RuleLevel::Class_, RuleLevel::Namespace_],
            $rule->getSupportedLevels(),
        );
    }

    public function testAnalyzeLevelClassDisabled(): void
    {
        $rule = new SizeRule(new SizeOptions(
            class: new ClassSizeOptions(enabled: false),
        ));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelNamespaceDisabled(): void
    {
        $rule = new SizeRule(new SizeOptions(
            namespace: new NamespaceLevelOptions(enabled: false),
        ));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Namespace_, $context));
    }

    // Class level tests

    public function testClassLevelReturnsEmptyWhenNoClasses(): void
    {
        $rule = new SizeRule(new SizeOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testClassLevelReturnsEmptyWhenBelowThreshold(): void
    {
        $rule = new SizeRule(new SizeOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', 5);

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

    public function testClassLevelGeneratesWarning(): void
    {
        $rule = new SizeRule(new SizeOptions(
            class: new ClassSizeOptions(warning: 10, error: 20),
        ));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', 15);

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
        self::assertSame('Class has 15 methods', $violations[0]->message);
        self::assertSame(15, $violations[0]->metricValue);
        self::assertSame('size', $violations[0]->ruleName);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    public function testClassLevelGeneratesError(): void
    {
        $rule = new SizeRule(new SizeOptions(
            class: new ClassSizeOptions(warning: 10, error: 20),
        ));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', 25);

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
        self::assertSame('Class has 25 methods', $violations[0]->message);
    }

    // Namespace level tests

    public function testNamespaceLevelReturnsEmptyWhenNoNamespaces(): void
    {
        $rule = new SizeRule(new SizeOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Namespace_, $context));
    }

    public function testNamespaceLevelReturnsEmptyWhenBelowThreshold(): void
    {
        $rule = new SizeRule(new SizeOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 0);

        $metricBag = (new MetricBag())->with('classCount.sum', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Namespace_, $context));
    }

    public function testNamespaceLevelGeneratesWarning(): void
    {
        $rule = new SizeRule(new SizeOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 0);

        // 18 classes is above warning (15) but below error (25)
        $metricBag = (new MetricBag())->with('classCount.sum', 18);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Namespace has 18 classes', $violations[0]->message);
        self::assertSame(18, $violations[0]->metricValue);
        self::assertSame('size', $violations[0]->ruleName);
        self::assertSame(RuleLevel::Namespace_, $violations[0]->level);
    }

    public function testNamespaceLevelGeneratesError(): void
    {
        $rule = new SizeRule(new SizeOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 0);

        // 30 classes is above error threshold (25)
        $metricBag = (new MetricBag())->with('classCount.sum', 30);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('Namespace has 30 classes', $violations[0]->message);
    }

    // Legacy analyze() method

    public function testAnalyzeRunsAllEnabledLevels(): void
    {
        $rule = new SizeRule(new SizeOptions(
            class: new ClassSizeOptions(warning: 5, error: 10),
            namespace: new NamespaceLevelOptions(warning: 5, error: 10),
        ));

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, 'src/Service/UserService.php', 10);
        $classMetrics = (new MetricBag())->with('methodCount', 7);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($nsPath, 'src/Service/UserService.php', 0);
        $nsMetrics = (new MetricBag())->with('classCount.sum', 7);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => match ($type) {
                SymbolType::Class_ => [$classInfo],
                SymbolType::Namespace_ => [$nsInfo],
                default => [],
            });
        $repository->method('get')
            ->willReturnCallback(fn(SymbolPath $path) => match ($path) {
                $classPath => $classMetrics,
                $nsPath => $nsMetrics,
                default => new MetricBag(),
            });

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
    }

    // Options tests

    public function testSizeOptionsFromArrayLegacyFormat(): void
    {
        $options = SizeOptions::fromArray([
            'enabled' => true,
            'warningThreshold' => 8,
            'errorThreshold' => 12,
        ]);

        self::assertFalse($options->class->isEnabled());
        self::assertTrue($options->namespace->isEnabled());
        self::assertSame(8, $options->namespace->warning);
        self::assertSame(12, $options->namespace->error);
    }

    public function testSizeOptionsFromArrayHierarchicalFormat(): void
    {
        $options = SizeOptions::fromArray([
            'class' => [
                'enabled' => true,
                'warning' => 20,
                'error' => 30,
            ],
            'namespace' => [
                'enabled' => false,
                'warning' => 8,
                'error' => 12,
            ],
        ]);

        self::assertTrue($options->class->isEnabled());
        self::assertSame(20, $options->class->warning);
        self::assertSame(30, $options->class->error);
        self::assertFalse($options->namespace->isEnabled());
        self::assertSame(8, $options->namespace->warning);
        self::assertSame(12, $options->namespace->error);
    }

    public function testSizeOptionsIsEnabledBothEnabled(): void
    {
        $options = new SizeOptions(
            class: new ClassSizeOptions(enabled: true),
            namespace: new NamespaceLevelOptions(enabled: true),
        );

        self::assertTrue($options->isEnabled());
    }

    public function testSizeOptionsIsEnabledOnlyClass(): void
    {
        $options = new SizeOptions(
            class: new ClassSizeOptions(enabled: true),
            namespace: new NamespaceLevelOptions(enabled: false),
        );

        self::assertTrue($options->isEnabled());
    }

    public function testSizeOptionsIsEnabledNone(): void
    {
        $options = new SizeOptions(
            class: new ClassSizeOptions(enabled: false),
            namespace: new NamespaceLevelOptions(enabled: false),
        );

        self::assertFalse($options->isEnabled());
    }

    public function testSizeOptionsForLevel(): void
    {
        $options = new SizeOptions();

        self::assertInstanceOf(ClassSizeOptions::class, $options->forLevel(RuleLevel::Class_));
        self::assertInstanceOf(NamespaceLevelOptions::class, $options->forLevel(RuleLevel::Namespace_));
    }

    public function testSizeOptionsForUnsupportedLevel(): void
    {
        $options = new SizeOptions();

        $this->expectException(InvalidArgumentException::class);
        $options->forLevel(RuleLevel::Method);
    }

    #[DataProvider('classThresholdDataProvider')]
    public function testClassThresholdBoundaries(
        int $methodCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new SizeRule(new SizeOptions(
            class: new ClassSizeOptions(warning: $warning, error: $error),
        ));

        $symbolPath = SymbolPath::forClass('App\Test', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', $methodCount);

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
    public static function classThresholdDataProvider(): iterable
    {
        yield 'below warning' => [14, 15, 25, null];
        yield 'at warning' => [15, 15, 25, Severity::Warning];
        yield 'above warning, below error' => [20, 15, 25, Severity::Warning];
        yield 'at error' => [25, 15, 25, Severity::Error];
        yield 'above error' => [30, 15, 25, Severity::Error];
    }

    #[DataProvider('namespaceThresholdDataProvider')]
    public function testNamespaceThresholdBoundaries(
        int $classCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new SizeRule(new SizeOptions(
            namespace: new NamespaceLevelOptions(warning: $warning, error: $error),
        ));

        $symbolPath = SymbolPath::forNamespace('App\Test');
        $nsInfo = new SymbolInfo($symbolPath, 'test.php', 0);

        $metricBag = (new MetricBag())->with('classCount.sum', $classCount);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

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
    public static function namespaceThresholdDataProvider(): iterable
    {
        yield 'below warning' => [9, 10, 15, null];
        yield 'at warning' => [10, 10, 15, Severity::Warning];
        yield 'above warning, below error' => [12, 10, 15, Severity::Warning];
        yield 'at error' => [15, 10, 15, Severity::Error];
        yield 'above error' => [20, 10, 15, Severity::Error];
    }
}
