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
use AiMessDetector\Rules\Complexity\ClassCognitiveComplexityOptions;
use AiMessDetector\Rules\Complexity\CognitiveComplexityOptions;
use AiMessDetector\Rules\Complexity\CognitiveComplexityRule;
use AiMessDetector\Rules\Complexity\MethodCognitiveComplexityOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CognitiveComplexityRule::class)]
#[CoversClass(CognitiveComplexityOptions::class)]
#[CoversClass(MethodCognitiveComplexityOptions::class)]
#[CoversClass(ClassCognitiveComplexityOptions::class)]
final class CognitiveComplexityRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame('cognitive', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame(
            'Checks cognitive complexity at method and class levels',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame(RuleCategory::Complexity, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame(['cognitive'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            CognitiveComplexityOptions::class,
            CognitiveComplexityRule::getOptionsClass(),
        );
    }

    public function testGetSupportedLevels(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame([RuleLevel::Method, RuleLevel::Class_], $rule->getSupportedLevels());
    }

    // Method-level tests

    public function testAnalyzeLevelMethodReturnsEmptyWhenDisabled(): void
    {
        $rule = new CognitiveComplexityRule(
            new CognitiveComplexityOptions(
                method: new MethodCognitiveComplexityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Method, $context));
    }

    public function testAnalyzeLevelMethodReturnsEmptyWhenNoMethods(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Method)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Method, $context));
    }

    public function testAnalyzeLevelMethodGeneratesWarning(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('cognitive', 20);

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
        self::assertSame('Cognitive complexity is 20, exceeds threshold of 15. Reduce nesting and break into smaller methods', $violations[0]->message);
        self::assertSame(20, $violations[0]->metricValue);
        self::assertSame('cognitive', $violations[0]->ruleName);
        self::assertSame(RuleLevel::Method, $violations[0]->level);
    }

    public function testAnalyzeLevelMethodGeneratesError(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('cognitive', 35);

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
        self::assertSame(35, $violations[0]->metricValue);
    }

    // Class-level tests

    public function testAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
    {
        $rule = new CognitiveComplexityRule(
            new CognitiveComplexityOptions(
                class: new ClassCognitiveComplexityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassGeneratesWarning(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 5);

        $metricBag = (new MetricBag())->with('cognitive.max', 35); // Above warning (30), below error (50)

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
        self::assertStringContainsString('Maximum method cognitive complexity is 35, exceeds threshold of 30', $violations[0]->message);
        self::assertSame(35, $violations[0]->metricValue);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    public function testAnalyzeLevelClassGeneratesError(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 5);

        $metricBag = (new MetricBag())->with('cognitive.max', 55); // Above error (50)

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
        self::assertSame(55, $violations[0]->metricValue);
    }

    // Legacy analyze() tests

    public function testAnalyzeCallsBothLevels(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $methodPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($methodPath, 'src/Service/UserService.php', 10);

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, 'src/Service/UserService.php', 5);

        $methodBag = (new MetricBag())->with('cognitive', 20); // Warning
        $classBag = (new MetricBag())->with('cognitive.max', 35); // Warning

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

    // Options tests

    public function testMethodOptionsFromArray(): void
    {
        $options = MethodCognitiveComplexityOptions::fromArray([
            'enabled' => false,
            'warning' => 20,
            'error' => 40,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(20, $options->warning);
        self::assertSame(40, $options->error);
    }

    public function testMethodOptionsFromEmptyArray(): void
    {
        $options = MethodCognitiveComplexityOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(15, $options->warning);
        self::assertSame(30, $options->error);
    }

    public function testClassOptionsFromArray(): void
    {
        $options = ClassCognitiveComplexityOptions::fromArray([
            'enabled' => false,
            'max_warning' => 40,
            'max_error' => 60,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(40, $options->maxWarning);
        self::assertSame(60, $options->maxError);
    }

    public function testCognitiveComplexityOptionsFromHierarchicalArray(): void
    {
        $options = CognitiveComplexityOptions::fromArray([
            'method' => [
                'warning' => 20,
                'error' => 35,
            ],
            'class' => [
                'max_warning' => 40,
                'max_error' => 60,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->method->isEnabled());
        self::assertSame(20, $options->method->warning);
        self::assertTrue($options->class->isEnabled());
        self::assertSame(40, $options->class->maxWarning);
    }

    public function testCognitiveComplexityOptionsFromLegacyArray(): void
    {
        $options = CognitiveComplexityOptions::fromArray([
            'enabled' => true,
            'warningThreshold' => 18,
            'errorThreshold' => 35,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->method->isEnabled());
        self::assertSame(18, $options->method->warning);
        self::assertSame(35, $options->method->error);
        // Legacy format disables class level
        self::assertFalse($options->class->isEnabled());
    }

    public function testCognitiveComplexityOptionsForLevel(): void
    {
        $options = new CognitiveComplexityOptions();

        self::assertSame($options->method, $options->forLevel(RuleLevel::Method));
        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
    }

    public function testCognitiveComplexityOptionsIsLevelEnabled(): void
    {
        $options = new CognitiveComplexityOptions(
            method: new MethodCognitiveComplexityOptions(enabled: true),
            class: new ClassCognitiveComplexityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Method));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Class_));
    }

    #[DataProvider('methodThresholdDataProvider')]
    public function testMethodThresholdBoundaries(
        int $cognitive,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new CognitiveComplexityRule(
            new CognitiveComplexityOptions(
                method: new MethodCognitiveComplexityOptions(
                    warning: $warning,
                    error: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('cognitive', $cognitive);

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
        yield 'below warning threshold' => [14, 15, 30, null];
        yield 'at warning threshold' => [15, 15, 30, Severity::Warning];
        yield 'above warning, below error' => [20, 15, 30, Severity::Warning];
        yield 'at error threshold' => [30, 15, 30, Severity::Error];
        yield 'above error threshold' => [40, 15, 30, Severity::Error];
    }
}
