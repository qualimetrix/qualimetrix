<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ClassComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Complexity\MethodComplexityOptions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

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

        self::assertSame([SymbolLevel::Callable, SymbolLevel::Class_], $rule->getSupportedLevels());
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(ComplexityRule::class);

        self::assertArrayHasKey('cyclomatic-warning', $aliases);
        self::assertArrayHasKey('cyclomatic-error', $aliases);
        self::assertArrayHasKey('cyclomatic-class-warning', $aliases);
        self::assertArrayHasKey('cyclomatic-class-error', $aliases);
        self::assertSame('callable.warning', $aliases['cyclomatic-warning']);
        self::assertSame('callable.error', $aliases['cyclomatic-error']);
        self::assertSame('class.max_warning', $aliases['cyclomatic-class-warning']);
        self::assertSame('class.max_error', $aliases['cyclomatic-class-error']);
    }

    // Method-level tests

    #[Test]
    public function itAnalyzeLevelMethodReturnsEmptyWhenDisabled(): void
    {
        $rule = new ComplexityRule(
            new ComplexityOptions(
                callable: new MethodComplexityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Callable, $context));
    }

    #[Test]
    public function itAnalyzeLevelMethodReturnsEmptyWhenNoMethods(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([]);
        $repository->method('allDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Callable, $context));
    }

    #[Test]
    public function itAnalyzeLevelMethodGeneratesWarning(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Callable, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('Cyclomatic complexity is 15, exceeds threshold of 10. Consider extracting methods or simplifying conditions', $findings[0]->message);
        self::assertSame(15, $findings[0]->metricValue);
        self::assertSame('complexity.cyclomatic', $findings[0]->ruleName);
        // Both CCN and cognitive are high — standard recommendation
        self::assertSame('Cyclomatic complexity: 15 (threshold: 10) — too many code paths', $findings[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodDivergenceRecommendation(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'handleStatus');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // High CCN but low cognitive — typical switch/match pattern
        $metricBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Callable, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('mechanical branching', $findings[0]->recommendation);
        self::assertStringContainsString('Lower refactoring priority', $findings[0]->recommendation);
        self::assertStringContainsString('cognitive complexity (5)', $findings[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodNoCognitiveFallsBackToStandardRecommendation(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // No cognitive metric available
        $metricBag = (new MetricBag())->with('ccn', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Callable, $context);

        self::assertCount(1, $findings);
        self::assertSame('Cyclomatic complexity: 15 (threshold: 10) — too many code paths', $findings[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodCognitiveAtThresholdNoSpecialRecommendation(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // Cognitive exactly at threshold (15) — no divergence
        $metricBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Callable, $context);

        self::assertCount(1, $findings);
        self::assertSame('Cyclomatic complexity: 15 (threshold: 10) — too many code paths', $findings[0]->recommendation);
    }

    #[Test]
    public function itAnalyzeLevelMethodGeneratesError(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('ccn', 25)->with('cognitive', 30);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Callable, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(25, $findings[0]->metricValue);
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

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Class_, $context));
    }

    #[Test]
    public function itAnalyzeLevelClassGeneratesWarning(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $metricBag = (new MetricBag())->with('ccn.max', 35); // Above warning (30), below error (50)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$classInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('Maximum method cyclomatic complexity is 35, exceeds threshold of 30', $findings[0]->message);
        self::assertSame(35, $findings[0]->metricValue);
    }

    #[Test]
    public function itAnalyzeLevelClassGeneratesError(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $metricBag = (new MetricBag())->with('ccn.max', 55); // Above error (50)

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$classInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(55, $findings[0]->metricValue);
    }

    // Legacy analyze() tests

    #[Test]
    public function itAnalyzeCallsBothLevels(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $methodPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($methodPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $methodBag = (new MetricBag())->with('ccn', 15)->with('cognitive', 20); // Warning
        $classBag = (new MetricBag())->with('ccn.max', 35); // Warning

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => $level === SymbolLevel::Class_ ? [$classInfo] : []);
        $repository->method('getSubject')->willReturn($methodBag);
        $repository->method('get')
            ->willReturnCallback(fn(SymbolPath $path) => match ($path) {
                $methodPath => $methodBag,
                $classPath => $classBag,
                default => new MetricBag(),
            });

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
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
            'callable' => [
                'warning' => 15,
                'error' => 25,
            ],
            'class' => [
                'max_warning' => 40,
                'max_error' => 60,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->callable->isEnabled());
        self::assertSame(15, $options->callable->warning);
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
        self::assertTrue($options->callable->isEnabled());
        self::assertSame(12, $options->callable->warning);
        self::assertSame(25, $options->callable->error);
        // Legacy format disables class level
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function itComplexityOptionsForLevel(): void
    {
        $options = new ComplexityOptions();

        self::assertSame($options->callable, $options->forLevel(SymbolLevel::Callable));
        self::assertSame($options->class, $options->forLevel(SymbolLevel::Class_));
    }

    #[Test]
    public function itComplexityOptionsIsLevelEnabled(): void
    {
        $options = new ComplexityOptions(
            callable: new MethodComplexityOptions(enabled: true),
            class: new ClassComplexityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(SymbolLevel::Callable));
        self::assertFalse($options->isLevelEnabled(SymbolLevel::Class_));
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
                callable: new MethodComplexityOptions(
                    warning: $warning,
                    error: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())->with('ccn', $ccn);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')
            ->willReturn([$methodInfo]);
        $repository->method('allDeclarations')
            ->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Callable, $context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
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
    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('get')->willReturn((new MetricBag())->with('ccn.max', 35));

        $findings = (new ComplexityRule(new ComplexityOptions()))
            ->analyzeLevel(SymbolLevel::Class_, new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    #[Test]
    public function itProjectsDuplicateLogicalCallableScoresToIndependentExactDeclarations(): void
    {
        $method = SymbolPath::forMethod('App\\Service', 'Twin', 'run');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([
            self::subjectInfo($method, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($method, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('getSubject')->willReturn((new MetricBag())->with('ccn', 15));

        $findings = (new ComplexityRule(new ComplexityOptions()))
            ->analyzeLevel(SymbolLevel::Callable, new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:callable:App\\Service\\Twin::run@src/A.php',
            'declaration:callable:App\\Service\\Twin::run@src/B.php',
        ], $subjects);
    }

    private static function subjectInfo(\Qualimetrix\Core\Symbol\SymbolPath $symbolPath, ?\Qualimetrix\Core\Path\RelativePath $file, ?int $line): \Qualimetrix\Core\Symbol\SymbolInfo
    {
        $type = $symbolPath->getType();
        if (\in_array($type, [\Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project], true)) {
            return new \Qualimetrix\Core\Symbol\SymbolInfo(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath), $file, $line);
        }

        \assert($file !== null);
        $kind = $type === \Qualimetrix\Core\Symbol\SymbolType::Class_ ? null : ($type === \Qualimetrix\Core\Symbol\SymbolType::Function_ ? \Qualimetrix\Core\Symbol\CallableKind::Function : \Qualimetrix\Core\Symbol\CallableKind::Method);

        return new \Qualimetrix\Core\Symbol\SymbolInfo(
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
