<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Complexity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Complexity\ClassNpathComplexityOptions;
use Qualimetrix\Rules\Complexity\MethodNpathComplexityOptions;
use Qualimetrix\Rules\Complexity\NpathComplexityOptions;
use Qualimetrix\Rules\Complexity\NpathComplexityRule;

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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Method, $context));
    }

    public function testAnalyzeLevelMethodGeneratesWarning(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('npath', 250); // Above warning (200), below error (1000)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('NPath complexity (execution paths) is 250 (moderate), exceeds threshold of 200. Reduce branching or extract methods', $violations[0]->message);
        self::assertSame(250, $violations[0]->metricValue);
        self::assertSame('complexity.npath', $violations[0]->ruleName);
        self::assertSame(RuleLevel::Method, $violations[0]->level);
    }

    public function testAnalyzeLevelMethodGeneratesError(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('npath', 1200); // Above error (1000)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(1200, $violations[0]->metricValue);
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

        $metricBag = (new MetricBag())->with('npath.max', 600); // Above warning (500), below error (1000)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Maximum method NPath complexity is 600 (moderate), exceeds threshold of 500', $violations[0]->message);
        self::assertSame(600, $violations[0]->metricValue);
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

        $metricBag = (new MetricBag())->with('npath.max', 1200); // Above error (1000)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(1200, $violations[0]->metricValue);
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
        $classBag = (new MetricBag())->with('npath.max', 600); // Warning

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

    // Large NPath display format test

    public function testLargeNpathDisplayFormat(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('npath', 2_500_000); // > 1M

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame('NPath complexity (execution paths) is > 1M (extreme), exceeds threshold of 1000. Reduce branching or extract methods', $violations[0]->message);
        self::assertSame(2_500_000, $violations[0]->metricValue);
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
        self::assertSame(1000, $options->error);
    }

    public function testClassOptionsFromArray(): void
    {
        $options = ClassNpathComplexityOptions::fromArray([
            'enabled' => true,
            'max_warning' => 400,
            'max_error' => 800,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(400, $options->maxWarning);
        self::assertSame(800, $options->maxError);
    }

    public function testClassOptionsFromEmptyArray(): void
    {
        $options = ClassNpathComplexityOptions::fromArray([]);

        self::assertFalse($options->enabled); // Default is false for class level
        self::assertSame(500, $options->maxWarning);
        self::assertSame(1000, $options->maxError);
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
        self::assertSame(300, $options->class->maxWarning);
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
        yield 'below warning threshold' => [199, 200, 500, null];
        yield 'at warning threshold' => [200, 200, 500, Severity::Warning];
        yield 'above warning, below error' => [350, 200, 500, Severity::Warning];
        yield 'at error threshold' => [500, 200, 500, Severity::Error];
        yield 'above error threshold' => [750, 200, 500, Severity::Error];
    }

    public function testLegacyDefaultErrorThresholdMatchesMethodDefault(): void
    {
        // Legacy format without explicit errorThreshold should use 1000 (same as MethodNpathComplexityOptions)
        $options = NpathComplexityOptions::fromArray([
            'warningThreshold' => 200,
        ]);

        self::assertSame(1000, $options->method->error);
    }

    public function testLegacyPartialConfigUsesCorrectDefaults(): void
    {
        $options = NpathComplexityOptions::fromArray([
            'errorThreshold' => 800,
        ]);

        self::assertSame(200, $options->method->warning);
        self::assertSame(800, $options->method->error);
    }

    public function testClassOptionsFromArrayWithCamelCase(): void
    {
        $options = ClassNpathComplexityOptions::fromArray([
            'enabled' => true,
            'maxWarning' => 400,
            'maxError' => 800,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(400, $options->maxWarning);
        self::assertSame(800, $options->maxError);
    }

    // Severity category label tests

    #[DataProvider('categoryLabelDataProvider')]
    public function testMethodViolationCategoryLabel(int $npath, string $expectedCategory): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                method: new MethodNpathComplexityOptions(
                    warning: 1,
                    error: 2,
                ),
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('npath', $npath);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertStringContainsString(\sprintf('(%s)', $expectedCategory), $violations[0]->message);
    }

    #[DataProvider('categoryLabelDataProvider')]
    public function testClassViolationCategoryLabel(int $npath, string $expectedCategory): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                class: new ClassNpathComplexityOptions(
                    enabled: true,
                    maxWarning: 1,
                    maxError: 2,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('npath.max', $npath);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertStringContainsString(\sprintf('(%s)', $expectedCategory), $violations[0]->message);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function categoryLabelDataProvider(): iterable
    {
        // Boundary: 1000 → moderate (<=1000)
        yield 'at 1000 boundary → moderate' => [1000, 'moderate'];
        // Boundary: 1001 → high (>1000)
        yield 'at 1001 boundary → high' => [1001, 'high'];
        // Mid-range high
        yield 'at 5000 → high' => [5000, 'high'];
        // Boundary: 10000 → high (<=10000)
        yield 'at 10000 boundary → high' => [10000, 'high'];
        // Boundary: 10001 → very high (>10000)
        yield 'at 10001 boundary → very high' => [10001, 'very high'];
        // Mid-range very high
        yield 'at 500000 → very high' => [500_000, 'very high'];
        // Boundary: 1000000 → very high (<=1000000)
        yield 'at 1000000 boundary → very high' => [1_000_000, 'very high'];
        // Boundary: 1000001 → extreme (>1000000)
        yield 'at 1000001 boundary → extreme' => [1_000_001, 'extreme'];
        // Well above extreme
        yield 'at 5000000 → extreme' => [5_000_000, 'extreme'];
        // Low values
        yield 'at 100 → moderate' => [100, 'moderate'];
        yield 'at 1 → moderate' => [1, 'moderate'];
    }

    public function testClassLevelLargeNpathDisplayFormat(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                class: new ClassNpathComplexityOptions(
                    enabled: true,
                    maxWarning: 500,
                    maxError: 1000,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 5);

        $metricBag = (new MetricBag())->with('npath.max', 2_500_000); // > 1M

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(
            'Maximum method NPath complexity is > 1M (extreme), exceeds threshold of 1000. Refactor the most complex methods',
            $violations[0]->message,
        );
        self::assertSame(2_500_000, $violations[0]->metricValue);
    }

    public function testMethodViolationRecommendationUsesDisplayValue(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                method: new MethodNpathComplexityOptions(
                    warning: 200,
                    error: 1000,
                ),
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('npath', 1_500_000);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertSame(
            'NPath complexity: > 1M (threshold: 1000) — explosive number of execution paths',
            $violations[0]->recommendation,
        );
    }

    public function testClassViolationRecommendationUsesDisplayValue(): void
    {
        $rule = new NpathComplexityRule(
            new NpathComplexityOptions(
                class: new ClassNpathComplexityOptions(
                    enabled: true,
                    maxWarning: 500,
                    maxError: 1000,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('npath.max', 1_500_000);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(
            'Max NPath complexity: > 1M (threshold: 1000) — explosive number of execution paths',
            $violations[0]->recommendation,
        );
    }

    public function testClassOptionsDefaultMaxWarningIs500(): void
    {
        $options = new ClassNpathComplexityOptions();

        self::assertSame(500, $options->maxWarning);
        self::assertSame(1000, $options->maxError);
    }

    public function testMethodViolationIncludesChainWhenEntriesPresent(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('npath', 1296)
            ->withEntry('npath-complexity.factors', ['type' => 'if/else', 'line' => 25, 'factor' => 6])
            ->withEntry('npath-complexity.factors', ['type' => 'match', 'line' => 31, 'factor' => 4])
            ->withEntry('npath-complexity.factors', ['type' => 'switch', 'line' => 20, 'factor' => 3]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        // Top 3 by factor: ×6 if/else, ×4 match, ×3 switch
        self::assertStringContainsString('Chain: ×6 if/else L25, ×4 match L31, ×3 switch L20.', $violations[0]->message);
        // recommendation: "NPath: 1296 (threshold: 200). Chain: ... — explosive"
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('. Chain:', $violations[0]->recommendation);
        self::assertStringContainsString('— explosive', $violations[0]->recommendation);
    }

    public function testMethodViolationNoChainWhenNoEntries(): void
    {
        $rule = new NpathComplexityRule(new NpathComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('npath', 1200);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Method, $context);

        self::assertCount(1, $violations);
        self::assertStringNotContainsString('Chain:', $violations[0]->message);
    }
}
