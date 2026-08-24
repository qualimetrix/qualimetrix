<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\CircularDependency\Cycle;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ConstructorOverinjectionOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\ConstructorOverinjectionRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRankOptions;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRankRule;
use Qualimetrix\Analysis\Evidence\Design\ParamTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverageOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateBlock;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateLocation;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityOptions;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Security\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternOptions;
use Qualimetrix\Analysis\Evidence\Size\ClassCountOptions;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveOptions;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveValidator;
use Qualimetrix\Analysis\Policy\Inline\Directive\UnusedDirectiveRule;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * Real-emission coverage guard: every channel exercised by this suite is
 * checked against the production {@see ChannelDeclarationRegistryInterface}
 * and must resolve to a declaration, or be recorded in
 * `tests/Analysis/Finding/Fixtures/Channels/excluded.txt` as deliberately not baselineable.
 *
 * Every case runs the REAL rule against a hand-built `AnalysisContext` (or,
 * for the inline-directive cases, a real prepared policy) — never a
 * hand-built `Finding` — so a wiring mistake (wrong channel key in
 * `channelDeclarations()`, a rule renamed without updating its declaration)
 * would show up as a real emitted channel the registry cannot resolve.
 *
 * Scope, stated accurately: this is a **representative corpus**, one case
 * per major category (Architecture, CodeSmell, Complexity, Coupling, Design,
 * Duplication, Maintainability, Security, Size) and per shape/direction
 * combination this package declares — `magnitude`/`higher`,
 * `magnitude`/`lower`, `occurrence` (both the fixed-marker and the
 * reports-a-number-but-declared-`occurrence`-anyway case, `coupling.class-rank`),
 * and the `annotation.*` family. It is **not** a hand-built emission
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

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
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

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
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

        $findings = $rule->analyzeLevel(SymbolLevel::Callable, new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
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

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
    }

    #[Test]
    public function theCircularDependencyMagnitudeChannelIsDeclared(): void
    {
        $cycles = [
            new Cycle(
                [SymbolPath::forClass('App', 'ServiceA'), SymbolPath::forClass('App', 'ServiceB')],
                [SymbolPath::forClass('App', 'ServiceA'), SymbolPath::forClass('App', 'ServiceB'), SymbolPath::forClass('App', 'ServiceA')],
            ),
        ];

        $analysis = new CircularDependencyAnalysis(new CircularDependencyDetector());
        $analysis->replace($cycles);
        $rule = new CircularDependencyRule(new CircularDependencyOptions(), $analysis);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($repository);

        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
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

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
    }

    #[Test]
    public function theTypeCoverageParamMagnitudeChannelIsDeclaredLowerIsWorse(): void
    {
        $rule = new ParamTypeCoverageRule(new TypeCoverageOptions(warning: 80.0, error: 50.0));

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

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
    }

    #[Test]
    public function theCodeDuplicationMagnitudeChannelIsDeclared(): void
    {
        $resultProvider = new DuplicationResultProvider();
        $resultProvider->replace([
            new DuplicateBlock(
                locations: [
                    new DuplicateLocation(RelativePath::fromString('src/A.php'), 10, 25),
                    new DuplicateLocation(RelativePath::fromString('src/B.php'), 30, 45),
                ],
                lines: 100,
                tokens: 200,
                contentHash: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ),
        ]);
        $rule = new CodeDuplicationRule(new CodeDuplicationOptions(), $resultProvider);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($repository);

        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
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

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
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

        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);

        self::assertDeclared($findings[0]->channel());
    }

    /**
     * The four inline-directive channels, through the rule that owns them.
     *
     * They used to be built straight inside the pipeline with no rule class
     * to declare them on, which is why they lived in `excluded.txt`. They now
     * have an owner, so what this case proves is the ordinary thing every
     * other case here proves: a real emission resolves to a real declaration.
     */
    #[Test]
    public function theInlineDirectiveChannelsAreDeclared(): void
    {
        $file = 'src/Foo.php';
        $subject = MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString($file)));

        $policy = self::directivePolicy();
        $policy->prepare(
            [
                $file => [
                    new Suppression('coupling.instabilty', 'typo', 10, SuppressionType::File),
                ],
            ],
            [
                $file => [
                    new ThresholdOverride(
                        rulePattern: 'code-smell.boolean-argument',
                        warning: 50.0,
                        error: 100.0,
                        line: 20,
                        subject: $subject,
                        controlScope: ControlScope::Class_,
                    ),
                ],
            ],
            [
                $file => [
                    new ThresholdDiagnostic(
                        line: 30,
                        subject: $subject,
                        message: '@qmx-threshold complexity.cyclomatic: warning (20) must not exceed error (10)',
                        code: 'warning_exceeds_error',
                    ),
                ],
            ],
        );

        $validator = new InlineDirectiveValidator(new InlineDirectiveOptions(), $policy, self::channelIdentity());
        $findings = $validator->validate(new AnalysisContext(self::createStub(MetricRepositoryInterface::class)));

        $emitted = array_map(static fn($finding): string => $finding->code, $findings);
        sort($emitted);
        self::assertSame(
            [
                InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME,
                InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
                InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME,
            ],
            $emitted,
        );

        foreach ($findings as $finding) {
            self::assertDeclared($finding->channel());
        }

        $unused = $policy->auditDirectiveUsage([]);
        self::assertCount(0, $unused, 'An unresolvable suppression is a configuration error, never stale debt.');
    }

    #[Test]
    public function theUnusedDirectiveChannelIsDeclared(): void
    {
        $file = 'src/Foo.php';

        $policy = self::directivePolicy();
        $policy->prepare(
            [$file => [new Suppression('code-smell.goto', 'no longer needed', 10, SuppressionType::File)]],
            [],
            [],
        );

        $context = new AnalysisContext(self::createStub(MetricRepositoryInterface::class));
        $options = new InlineDirectiveOptions();
        self::assertSame([], (new UnusedDirectiveRule($options, $policy))->analyze($context));
        self::assertSame([], (new InlineDirectiveValidator($options, $policy, self::channelIdentity()))->validate($context));

        $unused = $policy->auditDirectiveUsage([]);
        self::assertCount(1, $unused);
        self::assertSame(
            InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            $unused[0]->code,
        );

        self::assertDeclared($unused[0]->channel());
    }

    private static function directivePolicy(): InlineDirectivePolicy
    {
        return new InlineDirectivePolicy(
            self::channelIdentity(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            new RuleOptionsRegistry(),
        );
    }

    private static function channelIdentity(): ChannelIdentityInterface
    {
        $identity = (new ContainerFactory())->create()->get(ChannelIdentityInterface::class);
        \assert($identity instanceof ChannelIdentityInterface);

        return $identity;
    }

    private static function callableInfo(string $member): SymbolInfo
    {
        $file = RelativePath::fromString('src/Service/UserService.php');
        $logical = SymbolPath::forMethod('App\\Service', 'UserService', $member);

        return new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank(0))),
            $file,
            10,
        );
    }

    private static function classInfo(string $class, RelativePath $file): SymbolInfo
    {
        $logical = SymbolPath::forClass('App', $class);

        return new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank(0))),
            $file,
            10,
        );
    }

    private static function assertDeclared(FindingChannel $channel): void
    {
        self::assertNotNull(
            self::registry()->declarationFor($channel),
            \sprintf('Channel "%s" was emitted but the registry has no declaration for it.', $channel->toKey()),
        );
    }

    private static function registry(): ChannelDeclarationRegistryInterface
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        return $registry;
    }
}
