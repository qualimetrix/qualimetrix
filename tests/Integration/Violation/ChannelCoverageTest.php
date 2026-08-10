<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Violation;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Aggregator\GlobalCollectorRunner;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Collection\CollectionResult;
use Qualimetrix\Analysis\Collection\Dependency\Cycle;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\MetricEnricher;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Architecture\Rules\CircularDependencyOptions;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Duplication\DuplicateLocation;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Rules\CodeSmell\CodeSmellOptions;
use Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionOptions;
use Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionRule;
use Qualimetrix\Rules\CodeSmell\GotoRule;
use Qualimetrix\Rules\Complexity\ComplexityOptions;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Coupling\ClassRankOptions;
use Qualimetrix\Rules\Coupling\ClassRankRule;
use Qualimetrix\Rules\Design\TypeCoverageOptions;
use Qualimetrix\Rules\Design\TypeCoverageRule;
use Qualimetrix\Rules\Duplication\CodeDuplicationOptions;
use Qualimetrix\Rules\Duplication\CodeDuplicationRule;
use Qualimetrix\Rules\Maintainability\MaintainabilityOptions;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;
use Qualimetrix\Rules\Security\CommandInjectionRule;
use Qualimetrix\Rules\Security\SecurityPatternOptions;
use Qualimetrix\Rules\Size\ClassCountOptions;
use Qualimetrix\Rules\Size\ClassCountRule;
use Qualimetrix\Tests\Support\Pipeline\TestPipelineBuilder;
use RuntimeException;

/**
 * Real-emission coverage guard: every channel exercised by this suite is
 * checked against the production {@see ChannelDeclarationRegistryInterface}
 * and must resolve to a declaration, or be recorded in
 * `tests/Fixtures/Channels/excluded.txt` as deliberately not baselineable.
 *
 * Every case runs the REAL rule (or, for the two `annotation.*` cases, the
 * real pipeline) against a hand-built `AnalysisContext`/`CollectionResult` —
 * never a hand-built `Violation` — so a wiring mistake (wrong channel key in
 * `channelDeclarations()`, a rule renamed without updating its declaration)
 * would show up as a real emitted channel the registry cannot resolve.
 *
 * Scope, stated accurately: this is a **representative corpus**, one case
 * per major category (Architecture, CodeSmell, Complexity, Coupling, Design,
 * Duplication, Maintainability, Security, Size) and per shape/direction
 * combination this package declares — `magnitude`/`higher`,
 * `magnitude`/`lower`, `occurrence` (both the fixed-marker and the
 * reports-a-number-but-declared-`occurrence`-anyway case, `coupling.class-rank`),
 * and the excluded `annotation.*` family. It is **not** a hand-built emission
 * for all ~50 declared channels — each of those is separately verified by
 * the channel's own docblock file:line citation on its declaring rule, and
 * by {@see \Qualimetrix\Tests\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest},
 * which compares the complete static declared set against the tracked
 * fixture in both directions. This file's job is narrower and complementary:
 * prove that the declaration mechanism actually resolves real emissions, not
 * that every declaration is individually correct.
 */
#[CoversNothing]
final class ChannelCoverageTest extends TestCase
{
    #[Test]
    public function theGotoOccurrenceChannelIsDeclared(): void
    {
        $rule = new GotoRule(new CodeSmellOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Smelly.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Smelly.php'), null);
        $metricBag = (new MetricBag())->withEntry('codeSmell.goto', [
            'line' => 50,
            ...MetricSubjectCodec::encodeFile(),
        ]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theMaintainabilityIndexMagnitudeChannelIsDeclared(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $methodInfo = self::callableInfo('calculate');
        $metricBag = (new MetricBag())->with('mi', 10.0)->with('methodStatementCount', 50);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theCyclomaticComplexityMethodMagnitudeChannelIsDeclared(): void
    {
        $rule = new ComplexityRule(new ComplexityOptions());

        $methodInfo = self::callableInfo('calculate');
        $metricBag = (new MetricBag())->with('ccn', 25)->with('cognitive', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $violations = $rule->analyzeLevel(RuleLevel::Callable, new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theConstructorOverinjectionMagnitudeChannelIsDeclared(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 8, error: 12));

        $methodInfo = self::callableInfo('__construct');
        $metricBag = (new MetricBag())->with('parameterCount', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theCircularDependencyMagnitudeChannelIsDeclared(): void
    {
        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $cycles = [
            new Cycle(
                [SymbolPath::forClass('App', 'ServiceA'), SymbolPath::forClass('App', 'ServiceB')],
                [SymbolPath::forClass('App', 'ServiceA'), SymbolPath::forClass('App', 'ServiceB'), SymbolPath::forClass('App', 'ServiceA')],
            ),
        ];

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($repository, cycles: $cycles);

        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theClassRankOccurrenceDespiteNumberChannelIsDeclared(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $classInfo = self::classInfo('CriticalHub', RelativePath::fromString('src/CriticalHub.php'));
        // With one class, computeScaleFactor(1) = sqrt(1/100) = 0.1, so the
        // default error threshold (0.05) scales to 0.5 — 0.9 clears it.
        $metricBag = (new MetricBag())->with('classRank', 0.9);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theTypeCoverageParamMagnitudeChannelIsDeclaredLowerIsWorse(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(paramWarning: 80.0, paramError: 50.0));

        $classInfo = self::classInfo('TestClass', RelativePath::fromString('src/TestClass.php'));
        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 10)
            ->with('typeCoverage.paramTyped', 7)
            ->with('typeCoverage.param', 70.0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theCodeDuplicationMagnitudeChannelIsDeclared(): void
    {
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext(
            $repository,
            duplicateBlocks: [
                new DuplicateBlock(
                    locations: [
                        new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
                        new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                    ],
                    lines: 100,
                    tokens: 200,
                    contentHash: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                ),
            ],
        );

        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theCommandInjectionOccurrenceChannelIsDeclared(): void
    {
        $rule = new CommandInjectionRule(new SecurityPatternOptions());

        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Shell.php'));
        $fileInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Shell.php'), null);
        $metricBag = (new MetricBag())->withEntry('security.command_injection', [
            'line' => 20,
            'superglobal' => '',
            ...MetricSubjectCodec::encodeFile(),
        ]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::File ? [$fileInfo] : []);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theClassCountMagnitudeChannelIsDeclared(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 0);
        $metricBag = (new MetricBag())->with('classCount.sum', 30);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$namespaceInfo]);
        $repository->method('get')->willReturn($metricBag);

        $violations = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $violations);

        self::assertDeclared($violations[0]->channel());
    }

    #[Test]
    public function theUnsupportedThresholdAnnotationChannelResolvesToNullAndIsRecordedAsExcluded(): void
    {
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([]));

        $collectionOrchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult([], [], thresholdOverrides: [
                    'src/Foo.php' => [
                        new ThresholdOverride(
                            rulePattern: 'code-smell.boolean-argument',
                            warning: 50.0,
                            error: 100.0,
                            line: 10,
                            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php'))),
                            controlScope: ControlScope::Class_,
                            endLine: 50,
                        ),
                    ],
                ]),
                [],
            ),
        );

        // BooleanArgumentRule has a boolean-only Options class — it does not
        // implement ThresholdAwareOptionsInterface, which is exactly what
        // makes the annotation targeting it unsupported.
        $booleanArgRule = new BooleanArgumentRule(BooleanArgumentRule::getOptionsClass()::fromArray([]));

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);
        $ruleExecutor->method('getAllRules')->willReturn([$booleanArgRule]);

        $configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn(new AnalysisConfiguration());
        $configurationProvider->method('getRuleOptions')->willReturn([]);

        $metricEnricher = new MetricEnricher(
            compositeCollector: new CompositeCollector([]),
            globalCollectorRunner: new GlobalCollectorRunner([]),
            configurationProvider: $configurationProvider,
            logger: self::createStub(LoggerInterface::class),
        );

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($collectionOrchestrator)
            ->withRuleExecutor($ruleExecutor)
            ->withConfigurationProvider($configurationProvider)
            ->withMetricEnricher($metricEnricher)
            ->build();

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertCount(1, $result->violations);
        $channel = $result->violations[0]->channel();
        self::assertSame('annotation.unsupported-threshold', $channel->ruleName);

        self::assertExcluded($channel);
    }

    #[Test]
    public function theInvalidThresholdAnnotationChannelResolvesToNullAndIsRecordedAsExcluded(): void
    {
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([]));

        $collectionOrchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult([], [], thresholdDiagnostics: [
                    'src/Foo.php' => [
                        new ThresholdDiagnostic(
                            line: 10,
                            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php'))),
                            message: '@qmx-threshold complexity.cyclomatic: warning (20) must not exceed error (10)',
                            code: 'warning_exceeds_error',
                        ),
                    ],
                ]),
                [],
            ),
        );

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);
        $ruleExecutor->method('getAllRules')->willReturn([]);

        $configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn(new AnalysisConfiguration());
        $configurationProvider->method('getRuleOptions')->willReturn([]);

        $metricEnricher = new MetricEnricher(
            compositeCollector: new CompositeCollector([]),
            globalCollectorRunner: new GlobalCollectorRunner([]),
            configurationProvider: $configurationProvider,
            logger: self::createStub(LoggerInterface::class),
        );

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($collectionOrchestrator)
            ->withRuleExecutor($ruleExecutor)
            ->withConfigurationProvider($configurationProvider)
            ->withMetricEnricher($metricEnricher)
            ->build();

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertCount(1, $result->violations);
        $channel = $result->violations[0]->channel();
        self::assertSame('annotation.invalid-threshold', $channel->ruleName);
        self::assertSame('annotation.invalid-threshold.warning_exceeds_error', $channel->violationCode);

        // The specific `.warning_exceeds_error` code is not itself a fixture
        // line (the code vocabulary is open per-validator — see excluded.txt's
        // note); what must hold is that the channel is undeclared and that the
        // *family's* base entry documents why.
        $registry = self::registry();
        self::assertNull(
            $registry->declarationFor($channel),
            'annotation.invalid-threshold.* has no rule class to declare it on and must stay undeclared.',
        );
        self::assertContains(
            'annotation.invalid-threshold#annotation.invalid-threshold',
            self::readExcludedFixtureKeys(),
            'excluded.txt must document the annotation.invalid-threshold family.',
        );
    }

    private static function callableInfo(string $member): SymbolInfo
    {
        $file = RelativePath::fromString('src/Service/UserService.php');
        $logical = SymbolPath::forMethod('App\\Service', 'UserService', $member);

        return new SymbolInfo(
            MetricSubject::declaration(new DeclarationPath($logical, $file, 100)),
            $file,
            10,
        );
    }

    private static function classInfo(string $class, RelativePath $file): SymbolInfo
    {
        $logical = SymbolPath::forClass('App', $class);

        return new SymbolInfo(
            MetricSubject::declaration(new DeclarationPath($logical, $file, 100)),
            $file,
            10,
        );
    }

    private static function assertDeclared(ViolationChannel $channel): void
    {
        self::assertNotNull(
            self::registry()->declarationFor($channel),
            \sprintf('Channel "%s" was emitted but the registry has no declaration for it.', $channel->toKey()),
        );
    }

    private static function assertExcluded(ViolationChannel $channel): void
    {
        self::assertNull(
            self::registry()->declarationFor($channel),
            \sprintf('Channel "%s" resolves to a declaration but is expected to be deliberately excluded.', $channel->toKey()),
        );
        self::assertContains(
            $channel->toKey(),
            self::readExcludedFixtureKeys(),
            \sprintf('Channel "%s" is undeclared as expected, but excluded.txt does not record why.', $channel->toKey()),
        );
    }

    private static function registry(): ChannelDeclarationRegistryInterface
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        return $registry;
    }

    /**
     * @return list<string>
     */
    private static function readExcludedFixtureKeys(): array
    {
        $path = \dirname(__DIR__, 3) . '/tests/Fixtures/Channels/excluded.txt';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read fixture file %s.', $path));
        }

        $keys = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+--\s+/', $line, 2);
            $channelKey = $parts === false ? $line : $parts[0];
            $keys[] = trim($channelKey);
        }

        return $keys;
    }
}
