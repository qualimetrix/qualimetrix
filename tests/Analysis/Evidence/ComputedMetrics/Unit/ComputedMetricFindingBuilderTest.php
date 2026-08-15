<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRuleOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\ComputedMetricFindingBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(ComputedMetricFindingBuilder::class)]
final class ComputedMetricFindingBuilderTest extends TestCase
{
    #[Test]
    public function itEmitsNoViolationWhenInvertedMetricAboveWarningThreshold(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.score', 75.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itEmitsWarningWhenInvertedMetricBelowWarningAboveError(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.score', 40.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        // L10: threshold matches the warning threshold for warning severity
        self::assertSame(50.0, $violations[0]->threshold);
        self::assertSame(40.0, $violations[0]->metricValue);
    }

    #[Test]
    public function itEmitsErrorWhenInvertedMetricBelowErrorThreshold(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.score', 20.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itEmitsWarningWhenNormalMetricAboveWarningBelowError(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.complexity', 15.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function itEmitsErrorWhenNormalMetricAboveErrorThreshold(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.complexity', 25.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itFormatsViolationMessageCorrectly(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
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

    #[Test]
    public function itSetsViolationCodeToDefinitionName(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.custom', 15.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame('health.custom', $violations[0]->violationCode);
        self::assertSame('computed.health', $violations[0]->ruleName);
    }

    #[Test]
    public function itRoundsMetricValueToOneDecimal(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.precise', 15.678));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame(15.7, $violations[0]->metricValue);
    }

    #[Test]
    public function itUsesAboveInNormalMetricMessage(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.norm', 15.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertStringContainsString('above', $violations[0]->message);
        self::assertStringNotContainsString('below', $violations[0]->message);
    }

    #[Test]
    public function itUsesBelowInInvertedMetricMessage(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.inv', 40.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertStringContainsString('below', $violations[0]->message);
        self::assertStringNotContainsString('above', $violations[0]->message);
    }

    #[Test]
    public function itIncludesDimensionScoreAndThresholdInRecommendation(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
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

    #[Test]
    public function itExtractsDimensionLabelInRecommendation(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.cohesion', 30.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringContainsString('Cohesion health: 30.0 (threshold: 50.0)', $recommendation);
    }

    #[Test]
    public function itCarriesThresholdFieldInViolation(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
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

    #[Test]
    #[DataProvider('dimensionRecommendationProvider')]
    public function itHasDimensionSpecificRecommendation(string $dimensionName, string $expectedPrefix): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
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
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn($definitions);

        return new ComputedMetricRule(
            new ComputedMetricRuleOptions(enabled: true),
            $catalog,
            new ComputedMetricFindingBuilder(),
            self::createStub(ProfilerInterface::class),
        );
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
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(new \Qualimetrix\Core\Symbol\DeclarationPath($symbolPath, $file, $line ?? 0)),
            $file,
            $line,
            $kind,
        );
    }
}
