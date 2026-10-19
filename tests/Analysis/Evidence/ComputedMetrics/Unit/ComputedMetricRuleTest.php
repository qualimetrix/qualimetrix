<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricProducerOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRuleOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\ComputedMetricFindingBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(ComputedMetricRule::class)]
#[CoversClass(ComputedMetricRuleOptions::class)]
final class ComputedMetricRuleTest extends TestCase
{
    #[Test]
    #[\PHPUnit\Framework\Attributes\TestWith([true, 40.0, 50.0, 25.0])]
    #[\PHPUnit\Framework\Attributes\TestWith([false, 60.0, 50.0, 75.0])]
    public function itRetunesOnlyTheExactClassForOneBuiltinHealthProducer(bool $inverted, float $value, float $warning, float $error): void
    {
        $first = self::subjectInfo(SymbolPath::forClass('App', 'Record'), RelativePath::fromString('src/first.php'), 1);
        $second = self::subjectInfo(SymbolPath::forClass('App', 'Record'), RelativePath::fromString('src/second.php'), 1);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$first, $second]);
        $repository->method('getNamespaces')->willReturn(['App']);
        $values = MetricBag::fromArray(['health.cohesion' => $value, 'health.coupling' => $value]);
        $repository->method('getSubject')->willReturn($values);
        $repository->method('get')->willReturn($values);
        $definitions = [
            new ComputedMetricDefinition('health.cohesion', [], 'Cohesion', [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project], inverted: $inverted, warningThreshold: $warning, errorThreshold: $error),
            new ComputedMetricDefinition('health.coupling', [], 'Coupling', [SymbolLevel::Class_], inverted: $inverted, warningThreshold: $warning, errorThreshold: $error),
        ];
        $subject = $first->subject ?? throw new LogicException('Fixture needs an exact declaration.');
        $override = new \Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride(
            'health.cohesion',
            $value,
            $value,
            1,
            $subject,
            \Qualimetrix\Analysis\Finding\Contract\Control\ControlScope::Class_,
            20,
        );
        $context = new AnalysisContext($repository, thresholdOverrides: ['src/first.php' => [$override]]);
        $findings = $this->createRuleWithDefinitions($definitions)->analyze($context);

        self::assertCount(5, $findings);
        self::assertCount(3, array_filter($findings, static fn($finding): bool => $finding->code === 'health.cohesion'));
        self::assertCount(2, array_filter($findings, static fn($finding): bool => $finding->code === 'health.coupling'));
        self::assertSame([], array_values(array_filter($findings, static fn($finding): bool => $finding->code === 'health.cohesion' && $finding->subject->equals($subject))));
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\TestWith([40, 40, true])]
    #[\PHPUnit\Framework\Attributes\TestWith([1000000.0, 1000000.0, true])]
    #[\PHPUnit\Framework\Attributes\TestWith([null, 40, false])]
    #[\PHPUnit\Framework\Attributes\TestWith([40, null, false])]
    #[\PHPUnit\Framework\Attributes\TestWith([50, 40, false])]
    #[\PHPUnit\Framework\Attributes\TestWith([-1, -1, false])]
    public function itRequiresOneNonnegativeHealthOverrideBoundary(int|float|null $warning, int|float|null $error, bool $accepted): void
    {
        $axes = [];
        if ($warning !== null) {
            $axes[] = \Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis::Warning;
        }
        if ($error !== null) {
            $axes[] = \Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis::Error;
        }
        $request = new \Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest($warning, $error, \Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideSyntax::ExplicitAxes, $axes);
        self::assertSame($accepted, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\HealthThresholdOverrideValidator::forBuiltinHealthProducers()['health.cohesion']->validate($request) === null);
    }

    #[Test]
    public function itRefusesAHealthBoundaryWhoseDecimalOverflowsToInfinity(): void
    {
        $boundary = (float) (str_repeat('9', 400) . '.0');
        self::assertTrue(is_infinite($boundary));
        $request = new \Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest(
            $boundary,
            $boundary,
            \Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideSyntax::Shorthand,
            [],
        );

        $failure = \Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\HealthThresholdOverrideValidator::forBuiltinHealthProducers()['health.cohesion']->validate($request);

        self::assertNotNull($failure);
        self::assertSame('nonfinite_boundary', $failure->code);
    }

    #[Test]
    public function itAccountsTheEmptyProjectCoordinateWithoutInventingClassSubjects(): void
    {
        $definition = new ComputedMetricDefinition('computed.empty-project', ['project' => 'throw_if_evaluated()'], 'Project', [SymbolLevel::Project], warningThreshold: 5);
        $channel = new \Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel($definition->name);
        $publication = new \Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement([
            new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress(ComputedMetricRule::NAME, $channel, SymbolLevel::Project, \Qualimetrix\Analysis\Finding\Contract\Channel\ChannelSelectionRole::Selectable), new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct)),
        ], null));
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession(($publication)->publishes(...));
        $repository = new InMemoryMetricRepository();
        self::assertSame([], iterator_to_array($repository->allClassDeclarations(), false));
        self::assertSame([], $this->createRuleWithDefinitions([$definition])->analyze((new AnalysisContext($repository))->withPopulationTrace($session)));
        self::assertSame(0, $session->freeze()->judgedCount());
        self::assertSame(1, $session->freeze()->unjudgedCount());
        self::assertSame('project', $session->freeze()->abstentions()[0]->unit);
        self::assertSame(['project:'], $session->freeze()->abstentions()[0]->examples);
    }

    #[Test]
    public function itAccountsDefinitionOwnedValuesAndExcludesNonApplicableSubjectsWithoutEvaluatingFormulas(): void
    {
        $definitions = [
            new ComputedMetricDefinition('computed.declaration', ['class' => 'throw_if_evaluated()'], 'Authored', [SymbolLevel::Class_], warningThreshold: 5),
            new ComputedMetricDefinition('computed.policy', ['class' => 'throw_if_evaluated()'], 'Policy', [SymbolLevel::Class_], warningThreshold: 5, applicability: ['class' => \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricApplicability::positiveSum(['eligible'])]),
            new ComputedMetricDefinition('health.cohesion', ['class' => 'throw_if_evaluated()'], 'Builtin policy', [SymbolLevel::Class_], warningThreshold: 5, applicability: ['class' => \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricApplicability::anyPresent(['policy-input'])]),
            new ComputedMetricDefinition('computed.inherited', ['namespace' => 'throw_if_evaluated()'], 'Inherited policy', [SymbolLevel::Project], warningThreshold: 5, applicability: ['namespace' => \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricApplicability::anyPresent(['project-input'])]),
            new ComputedMetricDefinition('computed.project', ['project' => 'throw_if_evaluated()'], 'Project', [SymbolLevel::Project], warningThreshold: 5),
        ];
        $infos = [];
        foreach (['Missing', 'Healthy', 'Outside'] as $name) {
            $infos[] = self::subjectInfo(SymbolPath::forClass('Population', $name), RelativePath::fromString('src/' . $name . '.php'), 1);
        }
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn($infos);
        $repository->method('getSubject')->willReturnCallback(static function (MetricSubject $subject): MetricBag {
            return match ($subject->toSymbolPath()->type) {
                'Missing' => (new MetricBag())->with('eligible', 1)->with('policy-input', 1),
                'Healthy' => (new MetricBag())->with('eligible', 1)->with('computed.declaration', 0)->with('computed.policy', 0)->with('policy-input', 1)->with('health.cohesion', 0),
                'Outside' => (new MetricBag())->with('eligible', 0)->with('computed.declaration', 0)->with('computed.policy', 90)->with('health.cohesion', 90),
                default => (new MetricBag())->with('computed.inherited', 90),
            };
        });
        $decisions = [];
        foreach ($definitions as $definition) {
            $declaration = ComputedMetricChannelFamily::declarationFor($definition->name, $definition->reportingLevels(), $definition->inverted) ?? throw new LogicException('Fixture requires a reporting coordinate.');
            foreach ($declaration->levels as $level) {
                $decisions[] = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress($definition->producerRuleName(), new \Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel($definition->name), $level, \Qualimetrix\Analysis\Finding\Contract\Channel\ChannelSelectionRole::Selectable), new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct));
            }
        }
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession((new \Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement($decisions, null)))->publishes(...));
        $context = (new AnalysisContext($repository))->withPopulationTrace($session);
        self::assertSame([], $this->createRuleWithDefinitions($definitions)->analyze($context));
        self::assertSame(4, $session->freeze()->judgedCount());
        self::assertSame(4, $session->freeze()->unjudgedCount());
        self::assertSame(['computed.declaration', 'computed.policy', 'computed.project', 'health.cohesion'], array_map(static fn($absence): string => $absence->channel->code, $session->freeze()->abstentions()));
        self::assertSame('project', $session->freeze()->abstentions()[2]->unit);
        self::assertStringNotContainsString('Outside', implode(',', $session->freeze()->abstentions()[1]->examples));
    }

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

        self::assertSame('Checks user-defined computed metrics against their thresholds', $rule::getDescription());
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
        $repository->expects(self::never())->method('allClassDeclarations');

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
            levels: [SymbolLevel::Class_],
            inverted: true,
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/UserService.php'), 10)]);
        $repository->method('getSubject')
            ->willReturn(new MetricBag());

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itEmitsNoFindingsWhenNoThresholdsDefined(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.info',
            formulas: ['class' => 'complexity.ccn'],
            description: 'Info only metric',
            levels: [SymbolLevel::Class_],
        );

        $rule = $this->createRuleWithDefinitions([$definition]);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allClassDeclarations');

        $findings = $rule->analyze(new AnalysisContext($repository));

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itProcessesMultipleDefinitions(): void
    {
        $def1 = new ComputedMetricDefinition(
            name: 'health.alpha',
            formulas: ['class' => 'complexity.ccn'],
            description: 'Alpha',
            levels: [SymbolLevel::Class_],
            inverted: false,
            warningThreshold: 10.0,
        );
        $def2 = new ComputedMetricDefinition(
            name: 'health.beta',
            formulas: ['class' => 'size.loc'],
            description: 'Beta',
            levels: [SymbolLevel::Class_],
            inverted: false,
            warningThreshold: 100.0,
        );

        $rule = $this->createRuleWithDefinitions([$def1, $def2]);
        $classPath = SymbolPath::forClass('App', 'Test');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
        $repository->method('getSubject')
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
            formulas: ['class' => 'complexity.ccn', 'namespace' => 'avg(ccn)'],
            description: 'Multi-level',
            levels: [SymbolLevel::Class_, SymbolLevel::Namespace_],
            inverted: false,
            warningThreshold: 10.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Test');
        $nsPath = SymbolPath::forNamespace('App');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('test.php'), 1)]);
        $repository->method('getNamespaces')
            ->willReturn(['App']);
        $repository->method('getSubject')
            ->willReturnCallback(static function (MetricSubject $subject) use ($classPath, $nsPath): MetricBag {
                if ($subject->toSymbolPath()->toCanonical() === $classPath->toCanonical()) {
                    return (new MetricBag())->with('health.multi', 15.0);
                }
                if ($subject->toSymbolPath()->toCanonical() === $nsPath->toCanonical()) {
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
            levels: [SymbolLevel::Project],
            inverted: false,
            warningThreshold: 5.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $projectPath = SymbolPath::forProject();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('getSubject')
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
            levels: [SymbolLevel::Namespace_],
            inverted: false,
            warningThreshold: 5.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('getNamespaces')
            ->willReturn(['App\\Service']);
        $repository->method('getSubject')
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
            formulas: ['class' => 'complexity.ccn'],
            description: 'Class metric',
            levels: [SymbolLevel::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );

        $rule = $this->createRuleWithDefinitions([$definition]);
        $classPath = SymbolPath::forClass('App', 'Foo');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([self::subjectInfo($classPath, RelativePath::fromString('src/Foo.php'), 42)]);
        $repository->method('getSubject')
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
            formulas: ['class' => 'complexity.ccn'],
            description: 'Class metric',
            levels: [SymbolLevel::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );
        $repository = new InMemoryMetricRepository([new MetricDefinition('health.cls', SymbolLevel::Class_)]);
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
            formulas: ['class' => 'complexity.ccn'],
            description: 'Class metric',
            levels: [SymbolLevel::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );
        $class = SymbolPath::forClass('App', 'Foo');
        $first = $this->repositoryWithExactClassDeclaration($class, 'src/A.php', 100, 11);
        $second = $this->repositoryWithExactClassDeclaration($class, 'src/B.php', 200, 22);

        foreach ([($first->mergedWith($second) ?? throw new LogicException('In-memory repositories must be merge-compatible')), ($second->mergedWith($first) ?? throw new LogicException('In-memory repositories must be merge-compatible'))] as $repository) {
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
            formulas: ['class' => 'complexity.ccn'],
            description: 'Class metric',
            levels: [SymbolLevel::Class_],
            inverted: false,
            warningThreshold: 5.0,
        );
        $repository = new InMemoryMetricRepository([new MetricDefinition('health.cls', SymbolLevel::Class_)]);
        $class = SymbolPath::forClass('App', 'Foo');
        $owner = new LogicalClassPath($class);
        $method = SymbolPath::forMethod('App', 'Foo', 'run');
        $callable = new CallableWithMetrics(
            DeclarationPath::of($method, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)),
            100,
            CallableKind::Method,
            null,
            null,
            DeclarationPath::of($class, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)),
            new MetricBag(),
            42,
        );
        $repository->addCallable($callable);
        $repository->addSubject(
            MetricSubject::logicalClass($owner),
            new MetricBag(),
            null,
            null,
        );

        $findings = $this->createRuleWithDefinitions([$definition])->analyze(new AnalysisContext($repository));

        self::assertSame([], $findings);
    }

    /** One host serves the family, while the final invocation decides activity per producer. */
    #[Test]
    public function itRecordsOneProducerAsSwitchedOffWithoutItsNeighbours(): void
    {
        // A definition's name is not always its producer's: the six health
        // dimensions are named after theirs, while every user metric shares
        // the one open producer, `computed`. Both halves are here so the
        // record is checked on the mapping and not only on the easy identity.
        $definitionNames = [...ComputedMetricChannelFamily::HEALTH_PRODUCER_RULE_NAMES, 'computed.branch-load'];
        $off = $definitionNames[0];

        $written = [];
        $definitions = [];

        foreach (ComputedMetricChannelFamily::PRODUCER_RULE_NAMES as $producer) {
            $written[$producer] = ['enabled' => $producer !== $off];
        }

        foreach ($definitionNames as $name) {
            $definitions[] = new ComputedMetricDefinition(
                name: $name,
                formulas: ['class' => 'complexity.ccn'],
                description: $name,
                levels: [SymbolLevel::Class_],
                warningThreshold: 50.0,
                errorThreshold: 30.0,
            );
        }

        $metadata = array_map(
            static fn(string $name): RuleMetadata => new RuleMetadata($name, ComputedMetricRuleOptions::class, $name, [], false),
            ComputedMetricChannelFamily::PRODUCER_RULE_NAMES,
        );
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => ['rules' => $written]],
        ], AbsolutePath::fromString('/project'), $metadata);
        $universe = new ChannelUniverse(
            [],
            [],
            array_fill_keys(ComputedMetricChannelFamily::PRODUCER_RULE_NAMES, false),
            new ResolvedComputedMetricDefinitions($definitions),
            ...self::unusedReachPorts(),
        );
        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata, channels: $universe);
        $enablement = $ready->enablement;
        self::assertNotNull($enablement);
        $activity = $enablement->levelActivity()->toMap();

        self::assertNotSame([], $activity[$off] ?? [], 'the switched-off producer still declares its levels');
        self::assertSame([], array_filter($activity[$off], static fn(bool $live): bool => $live), $off . ' must be recorded as not run');

        foreach (\array_slice(ComputedMetricChannelFamily::PRODUCER_RULE_NAMES, 1) as $neighbour) {
            self::assertNotSame(
                [],
                array_filter($activity[$neighbour] ?? [], static fn(bool $live): bool => $live),
                $neighbour . ' must stay live while only ' . $off . ' is switched off',
            );
        }
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
        $repository = new InMemoryMetricRepository([new MetricDefinition('health.cls', SymbolLevel::Class_)]);
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
            $kind === \Qualimetrix\Core\Symbol\CallableKind::Method
                ? DeclarationPath::of(SymbolPath::forClass($symbolPath->namespace ?? '', $symbolPath->type ?? ''), $file, DeclarationOrdinal::fromRank(0))
                : null,
        );
    }

    /** @return array{\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface} */
    private static function unusedReachPorts(): array
    {
        return [
            new class implements \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface {
                public function metricReach(string $metricKey): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach
                {
                    throw new LogicException('This fixture does not query measured-metric reach.');
                }
            },
            new class implements \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface {
                public function reachAt(
                    string $metricName,
                    \Qualimetrix\Core\Symbol\SymbolLevel $level,
                    \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface $definitions,
                ): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach {
                    throw new LogicException('This fixture does not query computed-metric reach.');
                }
            },
        ];
    }
}
