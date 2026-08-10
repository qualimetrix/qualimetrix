<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\ComputedMetric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
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

    #[Test]
    public function itReturnsCorrectName(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame('computed.health', $rule->getName());
    }

    #[Test]
    public function itReturnsCorrectDescription(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame('Checks computed health metrics against thresholds', $rule->getDescription());
    }

    #[Test]
    public function itReturnsMaintainabilityCategory(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame(RuleCategory::Maintainability, $rule->getCategory());
    }

    #[Test]
    public function itRequiresNothing(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions());

        self::assertSame([], $rule->requires());
    }

    #[Test]
    public function itReturnsCorrectOptionsClass(): void
    {
        self::assertSame(ComputedMetricRuleOptions::class, ComputedMetricRule::getOptionsClass());
    }

    #[Test]
    public function itReturnsNoViolationsWhenDisabled(): void
    {
        $rule = new ComputedMetricRule(new ComputedMetricRuleOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

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
    public function itEmitsNoViolationWhenMetricAbsent(): void
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
            ->willReturn(new MetricBag());

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itEmitsNoViolationsWhenNoThresholdsDefined(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.info',
            formulas: ['class' => 'ccn'],
            description: 'Info only metric',
            levels: [SymbolType::Class_],
        );

        $rule = $this->createRuleWithDefinitions([$definition]);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $violations);
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
    public function itProcessesMultipleDefinitions(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
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

    #[Test]
    public function itProcessesMultipleLevels(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
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

    #[Test]
    public function itUsesNoneLocationForProjectLevel(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.project', 8.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertTrue($violations[0]->location->isNone());
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function itUsesNoneLocationForNamespaceLevel(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('getNamespaces')
            ->willReturn(['App\\Service']);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.ns', 8.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertTrue($violations[0]->location->isNone());
    }

    #[Test]
    public function itUsesFileAndLineForClassLevel(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/Foo.php'), 42)]);
        $repository->method('get')
            ->willReturn((new MetricBag())->with('health.cls', 10.0));

        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame('src/Foo.php', $violations[0]->location->pathString());
        self::assertSame(42, $violations[0]->location->line);
    }

    #[Test]
    public function itUsesTheUniqueExactClassDeclarationAsTheLogicalClassPresentationLocation(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.cls',
            formulas: ['class' => 'ccn'],
            description: 'Class metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );
        $repository = new InMemoryMetricRepository();
        $class = SymbolPath::forClass('App', 'Foo');
        $declaration = new DeclarationPath($class, RelativePath::fromString('src/Foo.php'), 100);
        $repository->addSubject(
            MetricSubject::declaration($declaration),
            MetricBag::fromArray(['health.cls' => 10.0]),
            $declaration->file,
            42,
        );

        $violations = $this->createRuleWithDefinitions([$definition])->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame('src/Foo.php', $violations[0]->location->pathString());
        self::assertSame(42, $violations[0]->location->line);
    }

    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarationsInEitherMergeOrder(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.cls',
            formulas: ['class' => 'ccn'],
            description: 'Class metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );
        $class = SymbolPath::forClass('App', 'Foo');
        $first = $this->repositoryWithExactClassDeclaration($class, 'src/A.php', 100, 11);
        $second = $this->repositoryWithExactClassDeclaration($class, 'src/B.php', 200, 22);

        foreach ([$first->mergeWith($second), $second->mergeWith($first)] as $repository) {
            $violations = $this->createRuleWithDefinitions([$definition])->analyze(new AnalysisContext($repository));

            self::assertCount(2, $violations);
            $subjects = array_map(static fn($violation): string => $violation->subject->toCanonical(), $violations);
            sort($subjects);
            self::assertSame([
                'declaration:class:App\\Foo@src/A.php:100',
                'declaration:class:App\\Foo@src/B.php:200',
            ], $subjects);
        }
    }

    #[Test]
    public function itDoesNotEmitClassScoresWithoutNamedClassDeclarations(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.cls',
            formulas: ['class' => 'ccn'],
            description: 'Class metric',
            levels: [SymbolType::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );
        $repository = new InMemoryMetricRepository();
        $class = SymbolPath::forClass('App', 'Foo');
        $owner = new LogicalClassPath($class);
        $method = SymbolPath::forMethod('App', 'Foo', 'run');
        $callable = new CallableWithMetrics(
            new DeclarationPath($method, RelativePath::fromString('src/Foo.php'), 100),
            CallableKind::Method,
            null,
            null,
            $owner,
            new MetricBag(),
            42,
        );
        $repository->addCallable($callable);
        $repository->addSubject(
            MetricSubject::logicalClass($owner),
            MetricBag::fromArray(['health.cls' => 10.0]),
            null,
            null,
        );

        $violations = $this->createRuleWithDefinitions([$definition])->analyze(new AnalysisContext($repository));

        self::assertSame([], $violations);
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
        return new ComputedMetricRule(
            new ComputedMetricRuleOptions(
                enabled: true,
                definitions: $definitions,
            ),
        );
    }

    private function repositoryWithExactClassDeclaration(
        SymbolPath $class,
        string $file,
        int $startFilePos,
        int $line,
    ): InMemoryMetricRepository {
        $repository = new InMemoryMetricRepository();
        $declaration = new DeclarationPath($class, RelativePath::fromString($file), $startFilePos);
        $repository->addSubject(
            MetricSubject::declaration($declaration),
            MetricBag::fromArray(['health.cls' => 10.0]),
            $declaration->file,
            $line,
        );

        return $repository;
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
