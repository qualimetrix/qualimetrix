<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Complexity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Complexity\ClassComplexityOptions;
use Qualimetrix\Rules\Complexity\ComplexityOptions;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Complexity\MethodComplexityOptions;

#[CoversClass(ComplexityRule::class)]
#[CoversClass(ComplexityOptions::class)]
#[CoversClass(MethodComplexityOptions::class)]
#[CoversClass(ClassComplexityOptions::class)]
final class ComplexityRuleTest extends TestCase
{
    #[Test]
    public function itGetName(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        self::assertSame('complexity.cyclomatic', $rule->getName());
    }

    #[Test]
    public function itGetDescription(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        self::assertSame(
            'Checks cyclomatic complexity at method and class levels',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itGetCategory(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        self::assertSame(RuleCategory::Complexity, $rule->getCategory());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        self::assertSame(['ccn', 'cognitive'], $rule->requires());
    }

    #[Test]
    public function itGetOptionsClass(): void
    {
        self::assertSame(
            ComplexityOptions::class,
            ComplexityRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itGetSupportedLevels(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        self::assertSame([RuleLevel::Method, RuleLevel::Class_], $rule->getSupportedLevels());
    }

    // Method-level tests

    #[Test]
    public function itAnalyzeLevelMethodReturnsEmptyWhenDisabled(): void
    {
        $rule = new ComplexityRule(
            new ComplexityOptions(
                method: new MethodComplexityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Method, $context));
    }

    #[Test]
    public function itAnalyzeLevelMethodReturnsEmptyWhenNoMethods(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Method, $context));
    }

    #[Test]
    public function itAnalyzeLevelMethodGeneratesWarning(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Cyclomatic complexity is 15, exceeds threshold of 10. Consider extracting methods or simplifying conditions', $violations[0]->message);
        self::assertSame(15, $violations[0]->metricValue);
        self::assertSame('complexity.cyclomatic', $violations[0]->ruleName);
        self::assertSame(RuleLevel::Method, $violations[0]->level);
        // Both CCN and cognitive are high — standard recommendation
        self::assertSame('Cyclomatic complexity: 15 (threshold: 10) — too many code paths', $violations[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodDivergenceRecommendation(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'handleStatus');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // High CCN but low cognitive — typical switch/match pattern
        $metricBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('mechanical branching', $violations[0]->recommendation);
        self::assertStringContainsString('Lower refactoring priority', $violations[0]->recommendation);
        self::assertStringContainsString('cognitive complexity (5)', $violations[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodNoCognitiveFallsBackToStandardRecommendation(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // No cognitive metric available
        $metricBag = (new MetricBag())->with('ccn', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame('Cyclomatic complexity: 15 (threshold: 10) — too many code paths', $violations[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodCognitiveAtThresholdNoSpecialRecommendation(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // Cognitive exactly at threshold (15) — no divergence
        $metricBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame('Cyclomatic complexity: 15 (threshold: 10) — too many code paths', $violations[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodGeneratesError(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('ccn', 25)->with('cognitive', 30);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(25, $violations[0]->metricValue);
    }

    // Class-level tests

    #[Test]
    public function itAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
    {
        $rule = new ComplexityRule(
            new ComplexityOptions(
                class: new ClassComplexityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    #[Test]
    public function itAnalyzeLevelClassGeneratesWarning(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $metricBag = (new MetricBag())->with('ccn.max', 35); // Above warning (30), below error (50)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Maximum method cyclomatic complexity is 35, exceeds threshold of 30', $violations[0]->message);
        self::assertSame(35, $violations[0]->metricValue);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    #[Test]
    public function itAnalyzeLevelClassGeneratesError(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $metricBag = (new MetricBag())->with('ccn.max', 55); // Above error (50)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(55, $violations[0]->metricValue);
    }

    // Legacy analyze() tests

    #[Test]
    public function itAnalyzeCallsBothLevels(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $methodPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($methodPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $methodBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 20); // Warning
        $classBag = (new MetricBag())->with('ccn.max', 35); // Warning

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itMethodOptionsFromArray(): void
    {
        $options = MethodComplexityOptions::fromArray([
            'enabled' => false,
            'warning' => 15,
            'error' => 30,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(15, $options->warning);
        self::assertSame(30, $options->error);
    }

    #[Test]
    public function itMethodOptionsFromEmptyArray(): void
    {
        $options = MethodComplexityOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    #[Test]
    public function itClassOptionsFromArray(): void
    {
        $options = ClassComplexityOptions::fromArray([
            'enabled' => false,
            'max_warning' => 40,
            'max_error' => 60,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(40, $options->maxWarning);
        self::assertSame(60, $options->maxError);
    }

    #[Test]
    public function itComplexityOptionsFromHierarchicalArray(): void
    {
        $options = ComplexityOptions::fromArray([
            'method' => [
                'warning' => 15,
                'error' => 25,
            ],
            'class' => [
                'max_warning' => 40,
                'max_error' => 60,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->method->isEnabled());
        self::assertSame(15, $options->method->warning);
        self::assertTrue($options->class->isEnabled());
        self::assertSame(40, $options->class->maxWarning);
    }

    #[Test]
    public function itComplexityOptionsFromLegacyArray(): void
    {
        $options = ComplexityOptions::fromArray([
            'enabled' => true,
            'warningThreshold' => 12,
            'errorThreshold' => 25,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->method->isEnabled());
        self::assertSame(12, $options->method->warning);
        self::assertSame(25, $options->method->error);
        // Legacy format disables class level
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function itComplexityOptionsForLevel(): void
    {
        $options = new ComplexityOptions();

        self::assertSame($options->method, $options->forLevel(RuleLevel::Method));
        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
    }

    #[Test]
    public function itComplexityOptionsIsLevelEnabled(): void
    {
        $options = new ComplexityOptions(
            method: new MethodComplexityOptions(enabled: true),
            class: new ClassComplexityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Method));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Class_));
    }

    #[DataProvider('methodThresholdDataProvider')]
    #[Test]
    public function itMethodThresholdBoundaries(
        int $ccn,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new ComplexityRule(
            new ComplexityOptions(
                method: new MethodComplexityOptions(
                    warning: $warning,
                    error: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = new SymbolInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())->with('ccn', $ccn);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
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
        yield 'below warning threshold' => [9, 10, 20, null];
        yield 'at warning threshold' => [10, 10, 20, Severity::Warning];
        yield 'above warning, below error' => [15, 10, 20, Severity::Warning];
        yield 'at error threshold' => [20, 10, 20, Severity::Error];
        yield 'above error threshold' => [30, 10, 20, Severity::Error];
    }
}
