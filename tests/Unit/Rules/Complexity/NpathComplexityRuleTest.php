<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Complexity;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Rules\Complexity\ClassNpathComplexityOptions;
use AiMessDetector\Rules\Complexity\MethodNpathComplexityOptions;
use AiMessDetector\Rules\Complexity\NpathComplexityOptions;
use AiMessDetector\Rules\Complexity\NpathComplexityRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NpathComplexityRule::class)]
#[CoversClass(NpathComplexityOptions::class)]
#[CoversClass(MethodNpathComplexityOptions::class)]
#[CoversClass(ClassNpathComplexityOptions::class)]
final class NpathComplexityRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        self::assertSame('complexity.npath', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        self::assertSame(
            'Checks NPath complexity at method and class levels',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        self::assertSame(RuleCategory::Complexity, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        self::assertSame(['npath'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            NpathComplexityOptions::class,
            NpathComplexityRule::getOptionsClass(),
        );
    }

    public function testGetSupportedLevels(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        self::assertSame([RuleLevel::Method, RuleLevel::Class_], $rule->getSupportedLevels());
    }

    // Method-level tests

    public function testAnalyzeLevelMethodReturnsEmptyWhenDisabled(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                method: new MethodNpathComplexityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Method, $context));
    }

    public function testAnalyzeLevelMethodReturnsEmptyWhenNoMethods(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Method)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Method, $context));
    }

    public function testAnalyzeLevelMethodGeneratesWarning(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('npath', 250); // Above warning (200), below error (500)

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Method)
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('NPath complexity is 250', $violations[0]->message);
        self::assertSame(250, $violations[0]->metricValue);
        self::assertSame('complexity.npath', $violations[0]->ruleName);
        self::assertSame(RuleLevel::Method, $violations[0]->level);
    }

    public function testAnalyzeLevelMethodGeneratesError(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('npath', 600); // Above error (500)

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Method)
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(600, $violations[0]->metricValue);
    }

    // Class-level tests

    public function testAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                class: new ClassNpathComplexityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassGeneratesWarning(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                class: new ClassNpathComplexityOptions(enabled: true),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 5);

        $metricBag = (new MetricBag())->with('npath.max', 250); // Above warning (200), below error (500)

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
        self::assertStringContainsString('maximum method NPath complexity of 250', $violations[0]->message);
        self::assertSame(250, $violations[0]->metricValue);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    public function testAnalyzeLevelClassGeneratesError(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                class: new ClassNpathComplexityOptions(enabled: true),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 5);

        $metricBag = (new MetricBag())->with('npath.max', 600); // Above error (500)

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
        self::assertSame(600, $violations[0]->metricValue);
    }

    // Legacy analyze() tests

    public function testAnalyzeCallsBothLevels(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                method: new MethodNpathComplexityOptions(enabled: true),
                class: new ClassNpathComplexityOptions(enabled: true),
            ),
        );

        $methodPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($methodPath, 'src/Service/UserService.php', 10);

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, 'src/Service/UserService.php', 5);

        $methodBag = (new MetricBag())->with('npath', 250); // Warning
        $classBag = (new MetricBag())->with('npath.max', 300); // Warning

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => match ($type) {
                SymbolType::Method => [$methodInfo],
                SymbolType::Class_ => [$classInfo],
                default => [],
            });
        $repository->method('get')
            ->willReturnCallback(fn(SymbolPath $path) => match ($path) {
                $methodPath => $methodBag,
                $classPath => $classBag,
                default => new MetricBag(),
            });

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(RuleLevel::Method, $violations[0]->level);
        self::assertSame(RuleLevel::Class_, $violations[1]->level);
    }

    // Large NPath display format test

    public function testLargeNpathDisplayFormat(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('npath', 1_500_000_000); // > 1 billion

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Method)
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame('NPath complexity is > 10^9', $violations[0]->message);
        self::assertSame(1_500_000_000, $violations[0]->metricValue);
    }

    // Options tests

    public function testMethodOptionsFromArray(): void
    {
        $options = MethodNpathComplexityOptions::fromArray([
            'enabled' => false,
            'warning' => 150,
            'error' => 300,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(150, $options->warning);
        self::assertSame(300, $options->error);
    }

    public function testMethodOptionsFromEmptyArray(): void
    {
        $options = MethodNpathComplexityOptions::fromArray([]);

        self::assertTrue($options->enabled); // Default is true for method level
        self::assertSame(200, $options->warning);
        self::assertSame(500, $options->error);
    }

    public function testClassOptionsFromArray(): void
    {
        $options = ClassNpathComplexityOptions::fromArray([
            'enabled' => true,
            'max_warning' => 400,
            'max_error' => 800,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(400, $options->max_warning);
        self::assertSame(800, $options->max_error);
    }

    public function testClassOptionsFromEmptyArray(): void
    {
        $options = ClassNpathComplexityOptions::fromArray([]);

        self::assertFalse($options->enabled); // Default is false for class level
        self::assertSame(200, $options->max_warning);
        self::assertSame(500, $options->max_error);
    }

    public function testNpathComplexityOptionsFromHierarchicalArray(): void
    {
        $options = NpathComplexityOptions::fromArray([
            'method' => [
                'warning' => 150,
                'error' => 400,
            ],
            'class' => [
                'enabled' => true,
                'max_warning' => 300,
                'max_error' => 600,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->method->isEnabled());
        self::assertSame(150, $options->method->warning);
        self::assertTrue($options->class->isEnabled());
        self::assertSame(300, $options->class->max_warning);
    }

    public function testNpathComplexityOptionsFromLegacyArray(): void
    {
        $options = NpathComplexityOptions::fromArray([
            'enabled' => true,
            'warningThreshold' => 180,
            'errorThreshold' => 450,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->method->isEnabled());
        self::assertSame(180, $options->method->warning);
        self::assertSame(450, $options->method->error);
        // Legacy format disables class level
        self::assertFalse($options->class->isEnabled());
    }

    public function testNpathComplexityOptionsForLevel(): void
    {
        $options = new NpathComplexityOptions();

        self::assertSame($options->method, $options->forLevel(RuleLevel::Method));
        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
    }

    public function testNpathComplexityOptionsIsLevelEnabled(): void
    {
        $options = new NpathComplexityOptions(
            method: new MethodNpathComplexityOptions(enabled: true),
            class: new ClassNpathComplexityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Method));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Class_));
    }

    #[DataProvider('methodThresholdDataProvider')]
    public function testMethodThresholdBoundaries(
        int $npath,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                method: new MethodNpathComplexityOptions(
                    warning: $warning,
                    error: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('npath', $npath);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Method)
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

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
    public static function methodThresholdDataProvider(): iterable
    {
        yield 'below warning threshold' => [199, 200, 500, null];
        yield 'at warning threshold' => [200, 200, 500, Severity::Warning];
        yield 'above warning, below error' => [350, 200, 500, Severity::Warning];
        yield 'at error threshold' => [500, 200, 500, Severity::Error];
        yield 'above error threshold' => [750, 200, 500, Severity::Error];
    }
}
