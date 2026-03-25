<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\ComputedMetric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\ComputedMetric\ComputedMetricRule;
use Qualimetrix\Rules\ComputedMetric\ComputedMetricRuleOptions;

#[CoversClass(ComputedMetricRule::class)]
#[CoversClass(ComputedMetricRuleOptions::class)]
final class ComputedMetricRuleTest extends TestCase
{
    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
    }

    public function testGetName(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame('computed.health', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame('Checks computed health metrics against thresholds', $rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame(RuleCategory::Maintainability, $rule->getCategory());
    }

    public function testRequiresReturnsEmpty(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame([], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(ComputedMetricRuleOptions::class, ComputedMetricRule::getOptionsClass());
    }

    public function testDisabledRuleReturnsNoViolations(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testNoViolationWhenInvertedMetricAboveWarningThreshold(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.score',
            formulas: ['class' => 'mi * 0.5'],
            description: 'Health score',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.score', 75.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $violations);
    }

    public function testWarningForInvertedMetricBelowWarningAboveError(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.score',
            formulas: ['class' => 'mi * 0.5'],
            description: 'Health score',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.score', 40.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        // L10: threshold matches the warning threshold for warning severity
        self::assertSame(50.0, $violations[0]->threshold);
        self::assertSame(40.0, $violations[0]->metricValue);
    }

    public function testErrorForInvertedMetricBelowErrorThreshold(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.score',
            formulas: ['class' => 'mi * 0.5'],
            description: 'Health score',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.score', 20.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testWarningForNormalMetricAboveWarningBelowError(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'ccn'],
            description: 'Complexity metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
            errorThreshold: 20.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.complexity', 15.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testErrorForNormalMetricAboveErrorThreshold(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'ccn'],
            description: 'Complexity metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
            errorThreshold: 20.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.complexity', 25.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testNoViolationWhenMetricAbsent(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.score',
            formulas: ['class' => 'mi * 0.5'],
            description: 'Health score',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn(new MetricBag());

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $violations);
    }

    public function testNoViolationsWhenNoThresholdsDefined(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.info',
            formulas: ['class' => 'ccn'],
            description: 'Info only metric',
            levels: [SymbolType::Class_],
        );

        $rule = $this->createRuleWithDefinitions([$definition]);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $violations);
    }

    public function testViolationMessageFormat(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.score',
            formulas: ['class' => 'mi'],
            description: 'Health score',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.score', 25.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(
            'App\Service\UserService: health.score = 25.0 (error threshold: below 30.0)',
            $violations[0]->message,
        );
        // L10: threshold must be set for programmatic filtering
        self::assertSame(30.0, $violations[0]->threshold);
        self::assertSame(25.0, $violations[0]->metricValue);
    }

    public function testViolationCodeEqualsDefinitionName(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.custom',
            formulas: ['class' => 'ccn'],
            description: 'Custom metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.custom', 15.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame('health.custom', $violations[0]->violationCode);
        self::assertSame('computed.health', $violations[0]->ruleName);
    }

    public function testMultipleDefinitionsProcessed(): void
    {
        $def1 = new ComputedMetricDefinition(
            name: 'health.alpha',
            formulas: ['class' => 'ccn'],
            description: 'Alpha',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
        );
        $def2 = new ComputedMetricDefinition(
            name: 'health.beta',
            formulas: ['class' => 'loc'],
            description: 'Beta',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 100.0,
        );

        $rule = $this->createRuleWithDefinitions([$def1, $def2]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn(
                (new MetricBag())
                    ->with('health.alpha', 15.0)
                    ->with('health.beta', 200.0),
            );

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(2, $violations);

        $codes = array_map(static fn($v) => $v->violationCode, $violations);
        self::assertContains('health.alpha', $codes);
        self::assertContains('health.beta', $codes);
    }

    public function testMultipleLevels(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.multi',
            formulas: ['class' => 'ccn', 'namespace' => 'avg(ccn)'],
            description: 'Multi-level',
            levels: [SymbolType::Class_, SymbolType::Namespace_],
            inverted: false,
            warningThreshold: 10.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');
        $nsPath = SymbolPath::forNamespace('App');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('getNamespaces')
            ->willReturn(['App']);
        $repository->method('get')
            ->willReturnCallback(static function (SymbolPath $path) use ($classPath, $nsPath): MetricBag {
                if ($path->toCanonical() === $classPath->toCanonical()) {
                    return (new MetricBag())->with('health.multi', 15.0);
                }
                if ($path->toCanonical() === $nsPath->toCanonical()) {
                    return (new MetricBag())->with('health.multi', 12.0);
                }

                return new MetricBag();
            });

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(2, $violations);
    }

    public function testProjectLevelUsesLocationNone(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.project',
            formulas: ['project' => 'avg(ccn)'],
            description: 'Project metric',
            levels: [SymbolType::Project],
            inverted: false,
            warningThreshold: 5.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $projectPath = SymbolPath::forProject();

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.project', 8.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertTrue($violations[0]->location->isNone());
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testNamespaceLevelUsesLocationNone(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.ns',
            formulas: ['namespace' => 'avg(ccn)'],
            description: 'NS metric',
            levels: [SymbolType::Namespace_],
            inverted: false,
            warningThreshold: 5.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('getNamespaces')
            ->willReturn(['App\\Service']);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.ns', 8.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertTrue($violations[0]->location->isNone());
    }

    public function testClassLevelUsesFileAndLine(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.cls',
            formulas: ['class' => 'ccn'],
            description: 'Class metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Foo');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/Foo.php', 42)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.cls', 10.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame('src/Foo.php', $violations[0]->location->file);
        self::assertSame(42, $violations[0]->location->line);
    }

    public function testMetricValueIsRoundedToOneDecimal(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.precise',
            formulas: ['class' => 'mi'],
            description: 'Precise metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.precise', 15.678));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(15.7, $violations[0]->metricValue);
    }

    public function testNormalMetricMessageUsesAbove(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.norm',
            formulas: ['class' => 'ccn'],
            description: 'Normal metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.norm', 15.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertStringContainsString('above', $violations[0]->message);
        self::assertStringNotContainsString('below', $violations[0]->message);
    }

    public function testInvertedMetricMessageUsesBelow(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.inv',
            formulas: ['class' => 'mi'],
            description: 'Inverted metric',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.inv', 40.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertStringContainsString('below', $violations[0]->message);
        self::assertStringNotContainsString('above', $violations[0]->message);
    }

    public function testRecommendationIncludesDimensionScoreAndThreshold(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'ccn'],
            description: 'Complexity metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
            errorThreshold: 20.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'src/UserService.php', 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.complexity', 25.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
        self::assertNotNull($recommendation);
        // Header: "Complexity health: 25.0 (threshold: 20.0)"
        self::assertStringContainsString('Complexity health: 25.0 (threshold: 20.0)', $recommendation);
        // Advice still present
        self::assertStringContainsString('Reduce complexity', $recommendation);
    }

    public function testRecommendationDimensionLabelExtraction(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.cohesion',
            formulas: ['class' => 'tcc'],
            description: 'Cohesion metric',
            levels: [SymbolType::Class_],
            inverted: true,
            warningThreshold: 50.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.cohesion', 30.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringContainsString('Cohesion health: 30.0 (threshold: 50.0)', $recommendation);
    }

    public function testViolationCarriesThresholdField(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'ccn'],
            description: 'Complexity',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
            errorThreshold: 20.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.complexity', 25.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(20.0, $violations[0]->threshold);
        self::assertSame(25.0, $violations[0]->metricValue);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dimensionRecommendationProvider(): array
    {
        return [
            'complexity dimension' => ['health.complexity', 'Reduce complexity'],
            'cohesion dimension' => ['health.cohesion', 'Improve class cohesion'],
            'coupling dimension' => ['health.coupling', 'Reduce coupling'],
            'design dimension' => ['health.design', 'Improve design'],
            'maintainability dimension' => ['health.maintainability', 'Improve maintainability'],
            'unknown dimension' => ['health.custom', 'Review the metric value'],
        ];
    }

    #[DataProvider('dimensionRecommendationProvider')]
    public function testViolationHasDimensionSpecificRecommendation(string $dimensionName, string $expectedPrefix): void
    {
        $definition = new ComputedMetricDefinition(
            name: $dimensionName,
            formulas: ['class' => 'ccn'],
            description: 'Test dimension',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 10.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([new SymbolInfo($classPath, 'test.php', 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with($dimensionName, 15.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringContainsString($expectedPrefix, $recommendation);
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function createRuleWithDefinitions(array $definitions): ComputedMetricRule
    {
        return new ComputedMetricRule(
            new ComputedMetricRuleOptions(
                enabled: true,
                definitions: $definitions,
            ),
        );
    }
}
