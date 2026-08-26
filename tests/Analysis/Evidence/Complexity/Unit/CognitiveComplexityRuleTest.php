<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ClassCognitiveComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\CognitiveComplexityRule;
use Qualimetrix\Analysis\Evidence\Complexity\MethodCognitiveComplexityOptions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CognitiveComplexityRule::class)]
#[CoversClass(CognitiveComplexityOptions::class)]
#[CoversClass(MethodCognitiveComplexityOptions::class)]
#[CoversClass(ClassCognitiveComplexityOptions::class)]
final class CognitiveComplexityRuleTest extends TestCase
{
    #[Test]
    public function itGetName(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame('complexity.cognitive', $rule->getName());
    }

    #[Test]
    public function itGetDescription(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame(
            'Checks cognitive complexity at method and class levels',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itGetCategory(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame(RuleCategory::Complexity, $rule->getCategory());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame(['cognitive'], $rule->requires());
    }

    #[Test]
    public function itGetOptionsClass(): void
    {
        self::assertSame(
            CognitiveComplexityOptions::class,
            CognitiveComplexityRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itGetSupportedLevels(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        self::assertSame([SymbolLevel::Callable, SymbolLevel::Class_], $rule->getSupportedLevels());
    }

    // Method-level tests

    #[Test]
    public function itAnalyzeLevelMethodReturnsEmptyWhenDisabled(): void
    {
        $rule = new CognitiveComplexityRule(
            new CognitiveComplexityOptions(
                callable: new MethodCognitiveComplexityOptions(enabled: false),
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
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

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
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('cognitive', 20);

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
        self::assertSame('Cognitive complexity is 20, exceeds threshold of 15. Reduce nesting and break into smaller methods', $findings[0]->message);
        self::assertSame(20, $findings[0]->metricValue);
        self::assertSame('complexity.cognitive', $findings[0]->ruleName);
    }

    #[Test]
    public function itAnalyzeLevelMethodGeneratesError(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('cognitive', 35);

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
        self::assertSame(35, $findings[0]->metricValue);
    }

    // Class-level tests

    #[Test]
    public function itAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
    {
        $rule = new CognitiveComplexityRule(
            new CognitiveComplexityOptions(
                class: new ClassCognitiveComplexityOptions(enabled: false),
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
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $metricBag = (new MetricBag())->with('cognitive.max', 35); // Above warning (30), below error (50)

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
        self::assertStringContainsString('Maximum method cognitive complexity is 35, exceeds threshold of 30', $findings[0]->message);
        self::assertSame(35, $findings[0]->metricValue);
    }

    #[Test]
    public function itAnalyzeLevelClassGeneratesError(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $metricBag = (new MetricBag())->with('cognitive.max', 55); // Above error (50)

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
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $methodPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($methodPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 5);

        $methodBag = (new MetricBag())->with('cognitive', 20); // Warning
        $classBag = (new MetricBag())->with('cognitive.max', 35); // Warning

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
        $options = MethodCognitiveComplexityOptions::fromArray([
            'enabled' => false,
            'warning' => 20,
            'error' => 40,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(20, $options->warning);
        self::assertSame(40, $options->error);
    }

    #[Test]
    public function itMethodOptionsFromEmptyArray(): void
    {
        $options = MethodCognitiveComplexityOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(15, $options->warning);
        self::assertSame(30, $options->error);
    }

    #[Test]
    public function itClassOptionsFromArray(): void
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

    #[Test]
    public function itCognitiveComplexityOptionsFromHierarchicalArray(): void
    {
        $options = CognitiveComplexityOptions::fromArray([
            'callable' => [
                'warning' => 20,
                'error' => 35,
            ],
            'class' => [
                'max_warning' => 40,
                'max_error' => 60,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->callable->isEnabled());
        self::assertSame(20, $options->callable->warning);
        self::assertTrue($options->class->isEnabled());
        self::assertSame(40, $options->class->maxWarning);
    }

    #[Test]
    public function itCognitiveComplexityOptionsFromLegacyArray(): void
    {
        $options = CognitiveComplexityOptions::fromArray([
            'enabled' => true,
            'warningThreshold' => 18,
            'errorThreshold' => 35,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->callable->isEnabled());
        self::assertSame(18, $options->callable->warning);
        self::assertSame(35, $options->callable->error);
        // Legacy format disables class level
        self::assertFalse($options->class->isEnabled());
    }

    #[Test]
    public function itCognitiveComplexityOptionsForLevel(): void
    {
        $options = new CognitiveComplexityOptions();

        self::assertSame($options->callable, $options->forLevel(SymbolLevel::Callable));
        self::assertSame($options->class, $options->forLevel(SymbolLevel::Class_));
    }

    #[Test]
    public function itCognitiveComplexityOptionsIsLevelEnabled(): void
    {
        $options = new CognitiveComplexityOptions(
            callable: new MethodCognitiveComplexityOptions(enabled: true),
            class: new ClassCognitiveComplexityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(SymbolLevel::Callable));
        self::assertFalse($options->isLevelEnabled(SymbolLevel::Class_));
    }

    #[DataProvider('methodThresholdDataProvider')]
    #[Test]
    public function itMethodThresholdBoundaries(
        int $cognitive,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new CognitiveComplexityRule(
            new CognitiveComplexityOptions(
                callable: new MethodCognitiveComplexityOptions(
                    warning: $warning,
                    error: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())->with('cognitive', $cognitive);

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
        yield 'below warning threshold' => [14, 15, 30, null];
        yield 'at warning threshold' => [15, 15, 30, Severity::Warning];
        yield 'above warning, below error' => [20, 15, 30, Severity::Warning];
        yield 'at error threshold' => [30, 15, 30, Severity::Error];
        yield 'above error threshold' => [40, 15, 30, Severity::Error];
    }

    #[Test]
    public function itLegacyDefaultErrorThresholdMatchesMethodDefault(): void
    {
        // Legacy format without explicit errorThreshold should use 30 (same as MethodCognitiveComplexityOptions)
        $options = CognitiveComplexityOptions::fromArray([
            'warningThreshold' => 15,
        ]);

        self::assertSame(30, $options->callable->error);
    }

    #[Test]
    public function itLegacyPartialConfigUsesCorrectDefaults(): void
    {
        $options = CognitiveComplexityOptions::fromArray([
            'errorThreshold' => 40,
        ]);

        self::assertSame(15, $options->callable->warning);
        self::assertSame(40, $options->callable->error);
    }

    #[Test]
    public function itMethodFindingIncludesBreakdownWhenEntriesPresent(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())
            ->with('cognitive', 25)
            ->withEntry('cognitive-complexity.increments', ['type' => 'if', 'line' => 12, 'points' => 5])
            ->withEntry('cognitive-complexity.increments', ['type' => 'foreach', 'line' => 15, 'points' => 4])
            ->withEntry('cognitive-complexity.increments', ['type' => '&&/||', 'line' => 22, 'points' => 1])
            ->withEntry('cognitive-complexity.increments', ['type' => 'else', 'line' => 30, 'points' => 1]);

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
        // Top 3 by points: if +5, foreach +4, &&/|| +1 (or else +1)
        self::assertStringContainsString('Top: nested if +5 L12, nested foreach +4 L15,', $findings[0]->message);
        // recommendation: "CC: 25 (threshold: 15). Top: ... — deeply nested"
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('. Top:', $findings[0]->recommendation);
        self::assertStringContainsString('— deeply nested', $findings[0]->recommendation);
    }

    #[Test]
    public function itBreakdownWithSingleIncrementAndClosureLabel(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())
            ->with('cognitive', 20)
            ->withEntry('cognitive-complexity.increments', ['type' => 'closure', 'line' => 15, 'points' => 3]);

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
        // Closure never gets "nested" prefix regardless of points
        self::assertStringContainsString('Top: closure +3 L15.', $findings[0]->message); // trailing "." from message format, not from breakdown
    }

    #[Test]
    public function itMethodFindingNoBreakdownWhenNoEntries(): void
    {
        $rule = new CognitiveComplexityRule(new CognitiveComplexityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())->with('cognitive', 20);

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
        self::assertStringNotContainsString('Top:', $findings[0]->message);
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
        $repository->method('get')->willReturn((new MetricBag())->with('cognitive.max', 35));

        $findings = (new CognitiveComplexityRule(new CognitiveComplexityOptions()))
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
        $repository->method('getSubject')->willReturn((new MetricBag())->with('cognitive', 20));

        $findings = (new CognitiveComplexityRule(new CognitiveComplexityOptions()))
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
