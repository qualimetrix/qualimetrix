<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricProducerOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRuleOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\ComputedMetricFindingBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(ComputedMetricRule::class)]
#[CoversClass(ComputedMetricRuleOptions::class)]
final class ComputedMetricRuleTest extends TestCase
{
    #[Test]
    public function itReturnsCorrectName(): void
    {
        $rule = $this->createRuleWithDefinitions([]);

        self::assertSame('computed', $rule->getName());
    }

    #[Test]
    public function itDescribesTheOpenHalfOfTheFamilyNotTheWholeOfIt(): void
    {
        $rule = $this->createRuleWithDefinitions([]);

        self::assertSame('Checks user-defined computed metrics against their thresholds', $rule->getDescription());
    }

    #[Test]
    public function itReturnsComputedCategory(): void
    {
        $rule = $this->createRuleWithDefinitions([]);

        self::assertSame(RuleCategory::Computed, $rule->getCategory());
    }

    #[Test]
    public function itRequiresNothing(): void
    {
        $rule = $this->createRuleWithDefinitions([]);

        self::assertSame([], $rule->requires());
    }

    #[Test]
    public function itReturnsCorrectOptionsClass(): void
    {
        self::assertSame(ComputedMetricRuleOptions::class, ComputedMetricRule::getOptionsClass());
    }

    #[Test]
    public function itReturnsNoFindingsWhenDisabled(): void
    {
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn([]);
        $rule = new ComputedMetricRule(
            new ComputedMetricRuleOptions(enabled: false),
            $catalog,
            new ComputedMetricFindingBuilder(),
            self::createStub(ProfilerInterface::class),
            self::producerOptions(enabled: false),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itEmitsNoFindingWhenMetricAbsent(): void
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

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itEmitsNoFindingsWhenNoThresholdsDefined(): void
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

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $findings);
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

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);

        $codes = array_map(static fn($v) => $v->code, $findings);
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

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
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

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->location->isNone());
        self::assertSame(Severity::Warning, $findings[0]->severity);
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

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->location->isNone());
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

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertSame('src/Foo.php', $findings[0]->location->pathString());
        self::assertSame(42, $findings[0]->location->line);
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
        $declaration = DeclarationPath::of($class, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0));
        $repository->addSubject(
            MetricSubject::declaration($declaration),
            MetricBag::fromArray(['health.cls' => 10.0]),
            $declaration->file,
            42,
        );

        $findings = $this->createRuleWithDefinitions([$definition])->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertSame('src/Foo.php', $findings[0]->location->pathString());
        self::assertSame(42, $findings[0]->location->line);
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
            $findings = $this->createRuleWithDefinitions([$definition])->analyze(new AnalysisContext($repository));

            self::assertCount(2, $findings);
            $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
            sort($subjects);
            self::assertSame([
                'declaration:class:App\\Foo@src/A.php',
                'declaration:class:App\\Foo@src/B.php',
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
            DeclarationPath::of($method, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)),
            100,
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

        $findings = $this->createRuleWithDefinitions([$definition])->analyze(new AnalysisContext($repository));

        self::assertSame([], $findings);
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
            self::producerOptions(),
        );
    }

    /** Every producer of the family enabled — the default the container builds. */
    private static function producerOptions(bool $enabled = true): ComputedMetricProducerOptions
    {
        $byProducer = [];

        foreach (ComputedMetricChannelFamily::PRODUCER_RULE_NAMES as $producer) {
            $byProducer[$producer] = new ComputedMetricRuleOptions(enabled: $enabled);
        }

        return new ComputedMetricProducerOptions($byProducer);
    }

    private function repositoryWithExactClassDeclaration(
        SymbolPath $class,
        string $file,
        int $startFilePos,
        int $line,
    ): InMemoryMetricRepository {
        $repository = new InMemoryMetricRepository();
        $declaration = DeclarationPath::of($class, RelativePath::fromString($file), DeclarationOrdinal::fromRank(0));
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
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
