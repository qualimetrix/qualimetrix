<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Rules;

use LogicException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyLocationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerEvidenceCollector;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationFinding;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\OwnedLayerTargets;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\LayerVerdicts;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ProcessorBuilder;

#[CoversClass(LayerViolationRule::class)]
#[CoversClass(LayerViolationFinding::class)]
#[CoversClass(OwnedLayerTargets::class)]
final class LayerViolationRuleTest extends TestCase
{
    /**
     * Per-test scratch processor shared between {@see buildRule()} and
     * {@see buildContext()}. {@see buildContext()} primes the processor
     * with the architecture under test so the rule under test reads the
     * prepared configuration through the injected processor instance.
     */
    private ArchitecturePolicy $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitecturePolicy();
    }

    #[Test]
    public function metadataMatchesContract(): void
    {
        $options = new LayerViolationOptions();
        $rule = new LayerViolationRule($options, new LayerEvidenceCollector($options, new UnassignedClassOptions(), $this->processor));

        self::assertSame('architecture.layer-violation', $rule->getName());
        self::assertSame([], $rule->requires());
        self::assertSame(LayerViolationOptions::class, LayerViolationRule::getOptionsClass());
        // Two aliases, not three: the unassigned-class gate went with the
        // producer that owns it.
        self::assertSame([
            'layer-violation' => 'enabled',
            'layer-violation-severity' => 'severity',
        ], CliAliasReader::read(LayerViolationRule::class));
        self::assertSame(
            ['architecture.layer-violation'],
            array_keys(LayerViolationRule::channelDeclarations()),
        );
        self::assertStringContainsString('layer', strtolower($rule->getDescription()));
    }

    #[Test]
    public function disabledRuleReturnsNoFindings(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions(enabled: false));

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'repository' => ['App\\Repository'],
            ],
            allow: ['controller' => []],
        );

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Repository', 'UserRepository'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, $arch)));
    }

    /**
     * The shared walk memoises per context, so an answer given before the run
     * primed the policy would be pinned for the rest of that run — both
     * verdicts silently reporting nothing. Refusing the state is what keeps
     * the memo honest; the second half is the part that goes red if the refusal
     * is replaced by a `return null`.
     */
    #[Test]
    public function anUnpreparedPolicyIsRefusedInsteadOfMemoisedAsAbsentEvidence(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions(severity: Severity::Error));

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'repository' => ['App\\Repository'],
            ],
            allow: ['controller' => []],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Controller', 'UserController');
        $this->registerClass($repo, 'App\\Repository', 'UserRepository');

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Repository', 'UserRepository'),
        ]);

        $context = $this->buildContext($graph, null, $repo);

        try {
            $rule->analyze($context);
            self::fail('An unprepared ArchitecturePolicy must be refused, not answered with absent evidence.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('unprepared ArchitecturePolicy', $exception->getMessage());
        }

        ProcessorBuilder::prepared($arch, $graph, $repo, $this->processor);

        self::assertNotSame(
            [],
            $this->filterByRule($rule->analyze($context), LayerViolationRule::NAME),
            'The refusal must leave nothing memoised: once the run prepares the policy, the same context has to'
            . ' yield the real verdict.',
        );
    }

    #[Test]
    public function emptyArchitectureReturnsNoFindings(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = new ArchitectureConfiguration(
            new LayerRegistry([]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Repository', 'UserRepository'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, $arch)));
    }

    #[Test]
    public function nullDependencyGraphProducesOnlyDiagnostics(): void
    {
        // With no graph, layer-violation cannot fire, but the per-class iteration
        // still drives unreachable-layer / potential-shadow.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: [],
        );

        // No classes either → no diagnostics.
        $findings = $rule->analyze($this->buildContext(null, $arch));
        // unreachable-layer fires because the controller layer matched nothing.
        self::assertCount(1, $findings);
        self::assertSame(LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME, $findings[0]->ruleName);
    }

    #[Test]
    public function allowedEdgeProducesNoFinding(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'service' => ['App\\Service'],
            ],
            allow: ['controller' => ['service']],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Controller', 'UserController');
        $this->registerClass($repo, 'App\\Service', 'UserService');

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Service', 'UserService'),
        ]);

        $findings = $rule->analyze($this->buildContext($graph, $arch, $repo));

        // No layer findings; no unreachable-layer (both layers had hits); no shadow.
        self::assertSame([], $findings);
    }

    #[Test]
    public function forbiddenEdgeProducesFindingWithExpectedFields(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions(severity: Severity::Error));

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'service' => ['App\\Service'],
                'repository' => ['App\\Repository'],
            ],
            allow: [
                'controller' => ['service'],
                'service' => ['repository'],
            ],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Controller', 'UserController');
        $this->registerClass($repo, 'App\\Service', 'UserService');
        $this->registerClass($repo, 'App\\Repository', 'UserRepository');

        $source = SymbolPath::forClass('App\\Controller', 'UserController');
        $target = SymbolPath::forClass('App\\Repository', 'UserRepository');
        $location = new Location(RelativePath::fromString('src/Controller/UserController.php'), 42, precise: true);

        $graph = $this->buildGraph([
            $this->dependency($source, $target, DependencyType::New_, $location),
        ]);

        $findings = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(1, $findings);
        $finding = $findings[0];

        self::assertSame('architecture.layer-violation', $finding->ruleName);
        self::assertSame(Severity::Error, $finding->severity);
        self::assertSame($source, $finding->symbolPath);
        self::assertSame(
            $this->findDeclarationSubject($repo, $target)->toCanonical(),
            $finding->subject->toCanonical(),
        );
        self::assertNotNull($finding->occurrenceKey);
        self::assertSame($location, $finding->location);
        self::assertSame($target, $finding->dependencyTarget);
        self::assertSame(DependencyType::New_, $finding->dependencyType);
        self::assertStringContainsString('Layer "controller" must not depend on layer "repository"', $finding->message);
        self::assertStringContainsString('App\\Controller\\UserController', $finding->message);
        self::assertStringContainsString('App\\Repository\\UserRepository', $finding->message);

        $recommendation = $finding->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringContainsString('Allowed targets for layer "controller": service', $recommendation);
        self::assertStringContainsString('Dep data: {', $recommendation);

        $jsonStart = strpos($recommendation, 'Dep data: ');
        self::assertIsInt($jsonStart);
        $payload = substr($recommendation, $jsonStart + \strlen('Dep data: '));
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);
        self::assertSame('controller', $decoded['fromLayer']);
        self::assertSame('repository', $decoded['toLayer']);
    }

    #[Test]
    public function recommendationListsGlobAllowTargetsAsTheirPatternStrings(): void
    {
        // Step C regression: when the source's allow row contains only
        // glob / captured selectors, the recommendation must NOT fall back to
        // "not allowed to depend on any other declared layer" — that wording
        // would be factually wrong. Pattern strings render verbatim so the
        // user sees the shape they can copy back into config.
        $rule = $this->buildRule(new LayerViolationOptions());

        $registry = new LayerRegistry([
            new LayerDefinition('controller', new MembershipSpec(['App\\Controller'])),
            new LayerDefinition('user-repository', new MembershipSpec(['App\\User\\Repository'])),
            new LayerDefinition('service', new MembershipSpec(['App\\Service'])),
        ]);
        $policy = new \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy([
            new \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowListEntry(
                \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector::exact('controller'),
                [new \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowTarget(
                    \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector::glob('*-repository'),
                )],
            ),
        ]);
        $arch = new ArchitectureConfiguration($registry, $policy, CoverageMode::Ignore);

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Controller', 'UserController');
        $this->registerClass($repo, 'App\\Service', 'UserService');
        $this->registerClass($repo, 'App\\User\\Repository', 'UserRepository');

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Service', 'UserService'),
        ]);

        $findings = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(1, $findings);
        $recommendation = $findings[0]->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringContainsString('Allowed targets for layer "controller": *-repository', $recommendation);
        self::assertStringNotContainsString('not allowed to depend on any', $recommendation);
    }

    #[Test]
    public function recommendationFallsBackToEmptyAllowListWording(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'core' => ['App\\Core'],
                'service' => ['App\\Service'],
            ],
            allow: [
                'core' => [],
            ],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Core', 'Kernel');
        $this->registerClass($repo, 'App\\Service', 'UserService');

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Core', 'Kernel', 'App\\Service', 'UserService'),
        ]);

        $findings = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(1, $findings);
        $recommendation = $findings[0]->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringContainsString(
            'Layer "core" is not allowed to depend on any other declared layer.',
            $recommendation,
        );
    }

    #[Test]
    public function eachUseSiteProducesItsOwnFinding(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'repository' => ['App\\Repository'],
            ],
            allow: ['controller' => []],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Controller', 'UserController');
        $this->registerClass($repo, 'App\\Repository', 'UserRepository');

        $source = SymbolPath::forClass('App\\Controller', 'UserController');
        $target = SymbolPath::forClass('App\\Repository', 'UserRepository');

        $graph = $this->buildGraph([
            $this->dependency($source, $target, DependencyType::New_, new Location(RelativePath::fromString('a.php'), 10)),
            $this->dependency($source, $target, DependencyType::TypeHint, new Location(RelativePath::fromString('a.php'), 20)),
        ]);

        $findings = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(2, $findings);
        self::assertSame(10, $findings[0]->location->line);
        self::assertSame(20, $findings[1]->location->line);
        self::assertNotSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value);
    }

    #[Test]
    public function itKeepsIdenticalSemanticEdgesStableAcrossUseSiteLocations(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $architecture = $this->buildArchitecture([
            'controller' => ['App\\Controller'],
            'repository' => ['App\\Repository'],
        ], ['controller' => []]);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller', 'Controller');
        $this->registerClass($repository, 'App\\Repository', 'Repository');
        $source = SymbolPath::forClass('App\\Controller', 'Controller');
        $target = SymbolPath::forClass('App\\Repository', 'Repository');
        $findings = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([
                $this->dependency($source, $target, DependencyType::New_, new Location(RelativePath::fromString('src/Controller.php'), 10)),
                $this->dependency($source, $target, DependencyType::New_, new Location(RelativePath::fromString('src/Controller.php'), 20)),
            ]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(2, $findings);
        self::assertSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value);
        self::assertSame([10, 20], array_map(
            static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $finding): ?int => $finding->location->line,
            $findings,
        ));
    }

    #[Test]
    public function itUsesTheExactSourceSubjectWhenTheLogicalTargetIsNotOwned(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $architecture = $this->buildArchitecture([
            'controller' => ['App\\Controller'],
            'vendor' => ['Vendor'],
        ], ['controller' => []]);
        $source = SymbolPath::forClass('App\\Controller', 'Controller');
        $dependency = $this->dependency(
            $source,
            SymbolPath::forClass('Vendor', 'External'),
            DependencyType::New_,
            new Location(RelativePath::fromString('src/Controller.php'), 12),
        );

        $findings = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture)),
            LayerViolationRule::NAME,
        );

        self::assertCount(1, $findings);
        self::assertSame(MetricSubject::declaration($dependency->source)->toCanonical(), $findings[0]->subject->toCanonical());
    }

    #[Test]
    public function itProjectsOneFindingToTheOwnedTargetDeclaration(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $architecture = $this->buildArchitecture([
            'controller' => ['App\\Controller'],
            'repository' => ['App\\Repository'],
        ], ['controller' => []]);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller', 'Controller');
        $targetSubject = $this->registerClass($repository, 'App\\Repository', 'Repository');
        $dependency = $this->buildDependency('App\\Controller', 'Controller', 'App\\Repository', 'Repository');

        $findings = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(1, $findings);
        self::assertSame($targetSubject->toCanonical(), $findings[0]->subject->toCanonical());
        self::assertSame($dependency->sourceLogical(), $findings[0]->symbolPath);
    }

    #[Test]
    public function itKeepsDuplicateExactSourceDeclarationsIndependentlyControlledWhenTargetIsUnowned(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $architecture = $this->buildArchitecture([
            'controller' => ['App\\Controller'],
            'vendor' => ['Vendor'],
        ], ['controller' => []]);
        $sourceLogical = SymbolPath::forClass('App\\Controller', 'Controller');
        $target = new LogicalClassPath(SymbolPath::forClass('Vendor', 'External'));
        $firstSource = DeclarationPath::of($sourceLogical, RelativePath::fromString('src/ControllerFirst.php'), DeclarationOrdinal::fromRank(0));
        $secondSource = DeclarationPath::of($sourceLogical, RelativePath::fromString('src/ControllerSecond.php'), DeclarationOrdinal::fromRank(0));
        $firstSubject = MetricSubject::declaration($firstSource);
        $secondSubject = MetricSubject::declaration($secondSource);
        $dependencies = [
            new Dependency($firstSource, $target, DependencyType::New_, new Location(RelativePath::fromString('src/ControllerFirst.php'), 5)),
            new Dependency($secondSource, $target, DependencyType::New_, new Location(RelativePath::fromString('src/ControllerSecond.php'), 5)),
        ];

        $findings = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph($dependencies), $architecture)),
            LayerViolationRule::NAME,
        );
        self::assertSame([$firstSubject->toCanonical(), $secondSubject->toCanonical()], array_map(
            static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $finding): string => $finding->subject->toCanonical(),
            $findings,
        ));

        $filter = new SuppressionFilter();
        $result = $filter->apply($findings, ['src/ControllerFirst.php' => [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Only the first exact source declaration is accepted.',
            line: 1,
            type: SuppressionType::Symbol,
            subject: $firstSubject,
            controlScope: ControlScope::Class_,
        )]]);
        self::assertSame([false, true], array_map(
            static fn($finding): bool => \in_array($finding, $result->retained, true),
            $findings,
        ));
    }

    #[Test]
    public function itProjectsEveryOwnedDuplicateTargetInCanonicalOrder(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $architecture = $this->buildArchitecture([
            'controller' => ['App\\Controller'],
            'repository' => ['App\\Repository'],
        ], ['controller' => []]);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller', 'Controller');
        $second = $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositorySecond.php', 20);
        $first = $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositoryFirst.php', 10);
        $dependency = $this->buildDependency('App\\Controller', 'Controller', 'App\\Repository', 'Repository');

        $findings = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(2, $findings);
        self::assertSame(
            [$first->toCanonical(), $second->toCanonical()],
            array_map(static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $finding): string => $finding->subject->toCanonical(), $findings),
        );
        self::assertNotSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value);
    }

    #[Test]
    public function itAppendsForbiddenEdgeFindingsInGraphAndCanonicalTargetOrder(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $architecture = $this->buildArchitecture([
            'controller' => ['App\\Controller'],
            'service' => ['App\\Service'],
            'repository' => ['App\\Repository'],
        ], ['controller' => ['service']]);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Controller', 'FirstController');
        $this->registerClass($repository, 'App\\Controller', 'SecondController');
        $this->registerClass($repository, 'App\\Service', 'AllowedService');
        $secondTarget = $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositorySecond.php', 20);
        $firstTarget = $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositoryFirst.php', 10);

        $dependencies = [
            $this->buildDependency('App\\Controller', 'FirstController', 'App\\Repository', 'Repository'),
            $this->buildDependency('App\\Controller', 'FirstController', 'App\\Service', 'AllowedService'),
            $this->buildDependency('App\\Controller', 'SecondController', 'App\\Repository', 'Repository'),
        ];

        $findings = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph($dependencies), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(4, $findings);
        self::assertSame([
            'class:App\\Controller\\FirstController|' . $firstTarget->toCanonical(),
            'class:App\\Controller\\FirstController|' . $secondTarget->toCanonical(),
            'class:App\\Controller\\SecondController|' . $firstTarget->toCanonical(),
            'class:App\\Controller\\SecondController|' . $secondTarget->toCanonical(),
        ], array_map(
            static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $finding): string => $finding->symbolPath->toCanonical() . '|' . $finding->subject->toCanonical(),
            $findings,
        ));
    }

    #[Test]
    public function itResolvesOwnedTargetDeclarationsInCanonicalOrder(): void
    {
        $repository = new InMemoryMetricRepository();
        $second = $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositorySecond.php', 20);
        $first = $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositoryFirst.php', 10);

        $targets = OwnedLayerTargets::fromDeclarations($repository->allDeclarations())->forLogical(
            SymbolPath::forClass('App\\Repository', 'Repository'),
        );

        self::assertSame([$first->toCanonical(), $second->toCanonical()], array_map(
            static fn(MetricSubject $subject): string => $subject->toCanonical(),
            $targets,
        ));
    }

    #[Test]
    public function itBuildsZeroOneAndManyPolicyApprovedTargetFindings(): void
    {
        $source = SymbolPath::forClass('App\\Controller', 'Controller');
        $target = SymbolPath::forClass('App\\Repository', 'Repository');
        $registry = new LayerRegistry([
            new LayerDefinition('controller', new MembershipSpec(['App\\Controller'])),
            new LayerDefinition('repository', new MembershipSpec(['App\\Repository'])),
        ]);
        $fromMatch = $registry->resolveAll($source)[0];
        $toMatch = $registry->resolveAll($target)[0];
        $dependency = $this->dependency(
            $source,
            $target,
            DependencyType::New_,
            new Location(RelativePath::fromString('src/Controller.php'), 12),
        );
        $first = MetricSubject::declaration(DeclarationPath::of($target, RelativePath::fromString('src/RepositoryFirst.php'), DeclarationOrdinal::fromRank(0)));
        $second = MetricSubject::declaration(DeclarationPath::of($target, RelativePath::fromString('src/RepositorySecond.php'), DeclarationOrdinal::fromRank(0)));

        $fallback = new LayerViolationFinding(
            dependency: $dependency,
            fromMatch: $fromMatch,
            toMatch: $toMatch,
            ownedTargets: [],
            ruleName: LayerViolationRule::NAME,
            severity: Severity::Warning,
            recommendation: 'Policy recommendation.',
        );
        $one = new LayerViolationFinding(
            dependency: $dependency,
            fromMatch: $fromMatch,
            toMatch: $toMatch,
            ownedTargets: [$first],
            ruleName: LayerViolationRule::NAME,
            severity: Severity::Warning,
            recommendation: 'Policy recommendation.',
        );
        $many = new LayerViolationFinding(
            dependency: $dependency,
            fromMatch: $fromMatch,
            toMatch: $toMatch,
            ownedTargets: [$first, $second],
            ruleName: LayerViolationRule::NAME,
            severity: Severity::Warning,
            recommendation: 'Policy recommendation.',
        );

        $fallbackFindings = $fallback->toFindings();
        $oneFindings = $one->toFindings();
        $manyFindings = $many->toFindings();

        self::assertSame(MetricSubject::declaration($dependency->source)->toCanonical(), $fallbackFindings[0]->subject->toCanonical());
        self::assertSame([$first->toCanonical()], array_map(
            static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $finding): string => $finding->subject->toCanonical(),
            $oneFindings,
        ));
        self::assertSame([$first->toCanonical(), $second->toCanonical()], array_map(
            static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $finding): string => $finding->subject->toCanonical(),
            $manyFindings,
        ));
        self::assertNotSame($manyFindings[0]->occurrenceKey?->value, $manyFindings[1]->occurrenceKey?->value);
        self::assertSame($dependency->location, $manyFindings[0]->location);
        self::assertSame($target, $manyFindings[0]->dependencyTarget);
        self::assertSame(DependencyType::New_, $manyFindings[0]->dependencyType);
        self::assertStringContainsString('Layer "controller" must not depend on layer "repository"', $manyFindings[0]->message);

        $sameEdgeAtAnotherLocation = new LayerViolationFinding(
            dependency: $this->dependency(
                $source,
                $target,
                DependencyType::New_,
                new Location(RelativePath::fromString('src/Controller.php'), 24),
            ),
            fromMatch: $fromMatch,
            toMatch: $toMatch,
            ownedTargets: [$first],
            ruleName: LayerViolationRule::NAME,
            severity: Severity::Warning,
            recommendation: 'Policy recommendation.',
        );
        $sameEdgeFinding = $sameEdgeAtAnotherLocation->toFindings()[0];

        self::assertSame($oneFindings[0]->occurrenceKey?->value, $sameEdgeFinding->occurrenceKey?->value);
        self::assertSame(24, $sameEdgeFinding->location->line);
    }

    #[Test]
    public function itKeepsTargetSymbolControlsIndependentWhileUseSitePhysicalControlsApplyToEveryProjection(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $architecture = $this->buildArchitecture([
            'controller' => ['App\\Controller'],
            'repository' => ['App\\Repository'],
        ], ['controller' => []]);
        $repository = new InMemoryMetricRepository();
        $sourceSubject = $this->registerClass($repository, 'App\\Controller', 'Controller');
        $firstTargetSubject = $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositoryOne.php', 10);
        $this->registerClass($repository, 'App\\Repository', 'Repository', 'src/RepositoryTwo.php', 20);
        $dependency = $this->dependency(
            SymbolPath::forClass('App\\Controller', 'Controller'),
            SymbolPath::forClass('App\\Repository', 'Repository'),
            DependencyType::New_,
            new Location(RelativePath::fromString('src/Controller.php'), 11),
        );
        $findings = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );
        self::assertCount(2, $findings);

        $filter = new SuppressionFilter();
        $suppressions = ['src/source.php' => [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Source declaration is independently controlled.',
            line: 1,
            type: SuppressionType::Symbol,
            subject: $sourceSubject,
            controlScope: ControlScope::Class_,
        )]];
        $result = $filter->apply($findings, $suppressions);
        self::assertSame([true, true], array_map(static fn($v): bool => \in_array($v, $result->retained, true), $findings));

        $suppressions['src/RepositoryOne.php'] = [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Target declaration control is independent.',
            line: 1,
            type: SuppressionType::Symbol,
            subject: $firstTargetSubject,
            controlScope: ControlScope::Class_,
        )];
        $result = $filter->apply($findings, $suppressions);
        self::assertSame([false, true], array_map(static fn($v): bool => \in_array($v, $result->retained, true), $findings));

        $suppressions['src/Controller.php'] = [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Physical use-site control applies to every projection.',
            line: 10,
            type: SuppressionType::NextLine,
        )];
        $result = $filter->apply($findings, $suppressions);
        self::assertSame([false, false], array_map(static fn($v): bool => \in_array($v, $result->retained, true), $findings));
    }

    #[Test]
    public function unmatchedSourceLayerEdgeIsIgnored(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['repository' => ['App\\Repository']],
            allow: ['repository' => []],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Repository', 'UserRepository');

        $graph = $this->buildGraph([
            $this->buildDependency('Other\\Vendor', 'Helper', 'App\\Repository', 'UserRepository'),
        ]);

        $findings = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertSame([], $findings);
    }

    #[Test]
    public function sameLayerEdgeIsIgnored(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['service' => ['App\\Service']],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Service', 'Foo');
        $this->registerClass($repo, 'App\\Service', 'Bar');

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Service', 'Foo', 'App\\Service', 'Bar'),
        ]);

        $findings = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertSame([], $findings);
    }

    // -------------------------------------------------------------------------
    // architecture.unreachable-layer diagnostic
    // -------------------------------------------------------------------------

    #[Test]
    public function unreachableLayer_firesWhenPatternMatchesNoClass(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        // Only the controller layer is declared, but no controller class exists.
        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller\\**']],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Service', 'UserService');

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $unreachable = $this->filterByRule($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertSame(Severity::Error, $unreachable[0]->severity);
        self::assertStringContainsString('Layer "controller" was never matched', $unreachable[0]->message);
        self::assertStringContainsString('App\\Controller\\**', $unreachable[0]->message);
        self::assertStringContainsString('qmx debug:layer-assignment', $unreachable[0]->message);
    }

    #[Test]
    public function unreachableLayer_firesForShadowedLayer(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        // The 'legacy' layer with pattern '**' captures everything; the
        // 'controller' layer declared afterwards is fully shadowed.
        $arch = $this->buildArchitecture(
            layers: [
                'legacy' => ['**'],
                'controller' => ['App\\Controller\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Controller', 'UserController');
        $this->registerClass($repo, 'App\\Service', 'UserService');

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $unreachable = $this->filterByRule($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('Layer "controller"', $unreachable[0]->message);
    }

    #[Test]
    public function itDoesNotReportUnreachableForALayerMatchedOnlyAsADependencyTarget(): void
    {
        // Regression: a layer that matches only as the TARGET of a dependency
        // edge — never as a class in the analysed set, e.g. a vendor
        // namespace outside `paths:` such as `ClickHouseDB\**` — must still
        // count as "reached". Before the fix, hit counting only walked
        // `metrics->all(Class_)`, so a vendor-only layer always landed at
        // zero hits and fired `unreachable-layer` in the very same run that
        // `layer-violation` reported an edge INTO it — a self-contradictory
        // pair of diagnostics.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'infrastructure' => ['App\\Infrastructure\\**'],
                'vendor-clickhouse' => ['ClickHouseDB\\**'],
                'typo-layer' => ['App\\DoesNotExist\\**'],
            ],
            allow: ['infrastructure' => []],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Infrastructure\\Health', 'ClickHouseCheck');
        // ClickHouseDB\Client is intentionally NOT registered — it lives
        // outside the analysed path set and is only observable via the
        // dependency edge target, exactly like real vendor code.

        $graph = $this->buildGraph([
            $this->buildDependency(
                'App\\Infrastructure\\Health',
                'ClickHouseCheck',
                'ClickHouseDB',
                'Client',
                DependencyType::TypeHint,
            ),
        ]);

        $findings = $rule->analyze($this->buildContext($graph, $arch, $repo));

        $layerViolations = $this->filterByRule($findings, LayerViolationRule::NAME);
        self::assertCount(1, $layerViolations, 'layer-violation must still fire for the disallowed edge.');
        self::assertStringContainsString(
            'Layer "infrastructure" must not depend on layer "vendor-clickhouse"',
            $layerViolations[0]->message,
        );

        $unreachableLayerNames = array_map(
            static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $v): string => self::extractLayerName($v->message),
            $this->filterByRule($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME),
        );

        self::assertNotContains(
            'vendor-clickhouse',
            $unreachableLayerNames,
            'vendor-clickhouse matched as a dependency target and must not be reported unreachable.',
        );
        // Sanity: a layer that matches nothing at all — neither a class nor
        // a dependency edge end — must still be reported (typo detection
        // must keep working).
        self::assertContains('typo-layer', $unreachableLayerNames);
    }

    private static function extractLayerName(string $message): string
    {
        preg_match('/Layer "([^"]+)"/', $message, $matches);

        return $matches[1] ?? '';
    }

    #[Test]
    public function unreachableLayer_doesNotFireForDtoOnlyLayer(): void
    {
        // The DTO layer's classes exist but have NO outgoing dependencies.
        // Because hit counting is over metrics->all(Class_) (not the graph),
        // the DTO layer must register a hit and not fire unreachable-layer.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['dto' => ['App\\Dto\\**']],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Dto', 'UserDto');

        // No dependency graph (no outgoing deps from DTO).
        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $unreachable = $this->filterByRule($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertSame([], $unreachable, 'DTO-only layer with no outgoing deps must NOT trigger unreachable-layer (hit counting is over classes, not graph edges).');
    }

    // -------------------------------------------------------------------------
    // architecture.potential-shadow diagnostic
    // -------------------------------------------------------------------------

    #[Test]
    public function potentialShadow_firesOnPrefixOverlap(): void
    {
        // Canonical example: 'any-foo' first matches anything ending in Foo;
        // 'service' second matches App\Service\*. App\Service\Foo matches both
        // and silently lands in any-foo.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'any-foo' => ['App\\**\\Foo'],
                'service' => ['App\\Service\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Service', 'Foo');
        $this->registerClass($repo, 'App\\Service', 'Bar');

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow);
        self::assertSame(Severity::Error, $shadow[0]->severity);
        self::assertStringContainsString('"any-foo"', $shadow[0]->message);
        self::assertStringContainsString('"service"', $shadow[0]->message);
        self::assertStringContainsString('App\\Service\\Foo', $shadow[0]->message);
    }

    #[Test]
    public function potentialShadow_firesOnSuffixTheft(): void
    {
        // Suffix-theft: '**\*Service' captures any class ending in Service
        // regardless of namespace. The narrower App\Domain\** layer declared
        // afterwards loses every *Service class.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'svc-suffix' => ['**\\*Service'],
                'domain' => ['App\\Domain\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Domain', 'OrderService');
        $this->registerClass($repo, 'App\\Domain', 'OrderRepository');

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow);
        self::assertStringContainsString('"svc-suffix"', $shadow[0]->message);
        self::assertStringContainsString('"domain"', $shadow[0]->message);
        self::assertStringContainsString('App\\Domain\\OrderService', $shadow[0]->message);
        // OrderRepository did not match svc-suffix → NOT in this diagnostic.
    }

    #[Test]
    public function potentialShadow_emptyClassSetEmitsNothing(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'a' => ['App\\**'],
                'b' => ['App\\Service\\**'],
            ],
            allow: [],
        );

        $findings = $rule->analyze($this->buildContext(null, $arch, new InMemoryMetricRepository()));

        $shadow = $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertSame([], $shadow);
    }

    #[Test]
    public function potentialShadow_disjointPatternsEmitNothing(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller\\**'],
                'service' => ['App\\Service\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Controller', 'UserController');
        $this->registerClass($repo, 'App\\Service', 'UserService');

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertSame([], $shadow);
    }

    #[Test]
    public function potentialShadow_truncatesSampleListAtFiveAndAppendsRemainderHint(): void
    {
        // Eight classes match both layers. The diagnostic shows the
        // alphabetically first five FQNs followed by "...and 3 more".
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'any' => ['App\\**'],
                'service' => ['App\\Service\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $names = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel'];
        foreach ($names as $name) {
            $this->registerClass($repo, 'App\\Service', $name);
        }

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow);

        $message = $shadow[0]->message;
        self::assertStringContainsString('for 8 class(es)', $message);
        // Alphabetically first five present.
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $sampled) {
            self::assertStringContainsString('App\\Service\\' . $sampled, $message);
        }
        // Last three suppressed from the sample.
        foreach (['Foxtrot', 'Golf', 'Hotel'] as $omitted) {
            self::assertStringNotContainsString('App\\Service\\' . $omitted, $message);
        }
        self::assertStringContainsString('...and 3 more', $message);
    }

    #[Test]
    public function potentialShadow_omitsRemainderHintWhenSampleFitsEntirely(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'any' => ['App\\**'],
                'service' => ['App\\Service\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        // Three classes — well below the sample limit of five.
        foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
            $this->registerClass($repo, 'App\\Service', $name);
        }

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow);
        self::assertStringNotContainsString('...and', $shadow[0]->message);
    }

    #[Test]
    public function potentialShadow_deterministicOutputAcrossTwoRuns(): void
    {
        // Two runs against the same fixture must emit diagnostics in identical
        // order regardless of metrics->all() iteration order.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'a' => ['App\\**'],
                'b' => ['App\\Service\\**'],
                'c' => ['App\\Service\\Special\\**'],
            ],
            allow: [],
        );

        // Multiple classes contributing to the same (assigned, shadowed) pair.
        $repo1 = new InMemoryMetricRepository();
        $this->registerClass($repo1, 'App\\Service', 'Zeta');
        $this->registerClass($repo1, 'App\\Service\\Special', 'Beta');
        $this->registerClass($repo1, 'App\\Service', 'Alpha');

        $repo2 = new InMemoryMetricRepository();
        // Reversed registration order.
        $this->registerClass($repo2, 'App\\Service', 'Alpha');
        $this->registerClass($repo2, 'App\\Service\\Special', 'Beta');
        $this->registerClass($repo2, 'App\\Service', 'Zeta');

        $run1 = $rule->analyze($this->buildContext(null, $arch, $repo1));
        $run2 = $rule->analyze($this->buildContext(null, $arch, $repo2));

        $shadow1 = $this->filterByRule($run1, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        $shadow2 = $this->filterByRule($run2, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);

        $msgs1 = array_map(static fn($v) => $v->message, $shadow1);
        $msgs2 = array_map(static fn($v) => $v->message, $shadow2);

        self::assertSame($msgs1, $msgs2, 'Shadow diagnostics must be lexicographically deterministic across runs.');
    }

    #[Test]
    public function itStaysSilentWhenTheWinningLayerIsNarrowerThanTheShadowedOne(): void
    {
        // "narrow before broad" — the idiom the documentation teaches. `app`
        // is not dead: it still owns everything outside App\Http.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'http' => ['App\\Http\\**'],
                'app' => ['App\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Http', 'Kernel');
        $this->registerClass($repo, 'App\\Domain', 'Customer');

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        self::assertSame([], $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itStaysSilentForTheDocumentedCatchAllIdiom(): void
    {
        // Verbatim shape of the "Ordering and the catch-all idiom" example in
        // website/docs/rules/architecture.md: a final `**` layer overlaps every
        // preceding one by construction.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'service' => ['App\\Service\\**'],
                'catchall' => ['**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Service', 'Billing');
        $this->registerClass($repo, 'App\\Other', 'Widget');

        $findings = $rule->analyze($this->buildContext(null, $arch, $repo));

        self::assertSame([], $this->filterByRule($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itFiresWhenTheBroaderLayerIsDeclaredFirst(): void
    {
        // The actual defect: `http` can never win in its own area.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'app' => ['App\\**'],
                'http' => ['App\\Http\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Http', 'Kernel');

        $shadow = $this->filterByRule(
            $rule->analyze($this->buildContext(null, $arch, $repo)),
            LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
        );

        self::assertCount(1, $shadow);
        self::assertStringContainsString('"app"', $shadow[0]->message);
        self::assertStringContainsString('"http"', $shadow[0]->message);
    }

    #[Test]
    public function itFiresWhenTheWinningLayerIsBroaderThanADeeperSubtree(): void
    {
        // `admin` is deeper than `http` but declared after it — the same defect
        // as the broad-first case, without a catch-all in sight.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'http' => ['App\\Http\\**'],
                'admin' => ['App\\Http\\Admin\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Http\\Admin', 'Dashboard');

        $shadow = $this->filterByRule(
            $rule->analyze($this->buildContext(null, $arch, $repo)),
            LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
        );

        self::assertCount(1, $shadow);
        self::assertStringContainsString('"http"', $shadow[0]->message);
        self::assertStringContainsString('"admin"', $shadow[0]->message);
    }

    #[Test]
    public function itKeepsTheDiagnosticWhenTheTwoCriteriaAreNotComparable(): void
    {
        // A suffix criterion has no namespace subtree to compare against a
        // pattern — the pair is undecidable and must stay reported.
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = new ArchitectureConfiguration(
            new LayerRegistry([
                new LayerDefinition('svc', new MembershipSpec(suffix: ['UserService'])),
                new LayerDefinition('app', new MembershipSpec(['App\\**'])),
            ]),
            AllowListBuilder::policyFromExactMap([]),
            CoverageMode::Ignore,
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Domain', 'UserService');

        $shadow = $this->filterByRule(
            $rule->analyze($this->buildContext(null, $arch, $repo)),
            LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
        );

        self::assertCount(1, $shadow);
        self::assertStringContainsString('suffix "UserService"', $shadow[0]->message);
    }

    // -------------------------------------------------------------------------
    // architecture.empty-template diagnostic
    // -------------------------------------------------------------------------

    #[Test]
    public function itReportsAnEmptyTemplateAtTheFixedConfigurationErrorSeverity(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitectureWithEmptyTemplates(['domain-{module}']);

        $findings = $rule->analyze($this->buildContext(null, $arch));

        $emptyTemplate = $this->filterByRule($findings, LayerDeclarationValidator::EMPTY_TEMPLATE_DIAGNOSTIC_NAME);
        self::assertCount(1, $emptyTemplate);
        self::assertSame(Severity::Error, $emptyTemplate[0]->severity);
        self::assertStringContainsString('domain-{module}', $emptyTemplate[0]->message);
    }

    // -------------------------------------------------------------------------
    // Statelessness regression — CLAUDE.md mandates stateless rules.
    // -------------------------------------------------------------------------

    #[Test]
    public function statelessness_consecutiveAnalyzeCallsDoNotLeakHitCountsOrShadowEvidence(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'a' => ['App\\**'],
                'b' => ['App\\Service\\**'],
            ],
            allow: [],
        );

        // Context 1: classes that trigger shadow.
        $repo1 = new InMemoryMetricRepository();
        $this->registerClass($repo1, 'App\\Service', 'Foo');

        $run1 = $rule->analyze($this->buildContext(null, $arch, $repo1));
        $shadow1 = $this->filterByRule($run1, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow1, 'First analyze() should produce one shadow diagnostic.');
        self::assertStringContainsString('1 class(es)', $shadow1[0]->message);

        // Context 2: empty repo — must NOT carry any state from run 1.
        $run2 = $rule->analyze($this->buildContext(null, $arch, new InMemoryMetricRepository()));
        $shadow2 = $this->filterByRule($run2, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertSame([], $shadow2, 'Second analyze() with empty context must produce zero shadow diagnostics — hit counter/shadow evidence must not leak.');

        // unreachable-layer for the second run fires on BOTH layers (no classes
        // means no hits anywhere).
        $unreachable2 = $this->filterByRule($run2, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(2, $unreachable2, 'Both layers must be reported unreachable on the empty second run.');
    }

    /**
     * @param array<string, list<string>> $layers
     * @param array<string, list<string>> $allow
     */
    private function buildArchitecture(array $layers, array $allow): ArchitectureConfiguration
    {
        $definitions = [];
        foreach ($layers as $name => $patterns) {
            $definitions[] = new LayerDefinition($name, new MembershipSpec($patterns));
        }

        return new ArchitectureConfiguration(
            new LayerRegistry($definitions),
            AllowListBuilder::policyFromExactMap($allow),
            CoverageMode::Ignore,
        );
    }

    /**
     * Builds an {@see ArchitectureConfiguration} carrying one unrelated
     * static layer (so {@see ArchitectureConfiguration::isEmpty()} stays
     * false and `analyze()` does not short-circuit before reaching the
     * empty-template diagnostic builder) plus the given empty-template
     * names, mirroring what
     * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage} would
     * populate for templates that expanded to zero concrete layers. Used to
     * unit-test {@code architecture.empty-template} without going through
     * the full expansion pipeline (that path is covered by
     * `LayerTemplateExpansionIntegrationTest`).
     *
     * @param list<string> $emptyTemplateNames
     */
    private function buildArchitectureWithEmptyTemplates(array $emptyTemplateNames): ArchitectureConfiguration
    {
        return new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('unrelated', new MembershipSpec(['App\\Unrelated\\**']))]),
            AllowListBuilder::policyFromExactMap([]),
            CoverageMode::Ignore,
            emptyTemplateNames: $emptyTemplateNames,
        );
    }

    #[Test]
    public function itPreservesTheDependencyLocationIdentityWhenProjectingAFinding(): void
    {
        $source = SymbolPath::forClass('App\\Controller', 'Controller');
        $target = SymbolPath::forClass('App\\Repository', 'Repository');
        $location = new Location(RelativePath::fromString('src/Controller.php'), 12);
        $finding = new LayerViolationFinding(
            dependency: $this->dependency($source, $target, DependencyType::New_, $location),
            fromMatch: (new LayerRegistry([new LayerDefinition('controller', new MembershipSpec(['App\\Controller']))]))->resolveAll($source)[0],
            toMatch: (new LayerRegistry([new LayerDefinition('repository', new MembershipSpec(['App\\Repository']))]))->resolveAll($target)[0],
            ownedTargets: [],
            ruleName: LayerViolationRule::NAME,
            severity: Severity::Warning,
            recommendation: 'Move the dependency behind an allowed boundary.',
        );

        self::assertSame($location, $finding->toFindings()[0]->location);
    }

    #[Test]
    public function itProjectsAStructuredDependencyLocationIntoAFindingLocation(): void
    {
        $source = SymbolPath::forClass('App\\Controller', 'Controller');
        $target = SymbolPath::forClass('App\\Repository', 'Repository');
        $location = new class implements DependencyLocationInterface {
            public function file(): RelativePath
            {
                return RelativePath::fromString('src/Controller.php');
            }

            public function line(): int
            {
                return 12;
            }

            public function toString(): string
            {
                return 'this string is deliberately not parsed';
            }
        };
        $finding = new LayerViolationFinding(
            dependency: new Dependency(
                DeclarationPath::of($source, RelativePath::fromString('src/Controller.php'), DeclarationOrdinal::fromRank(0)),
                new LogicalClassPath($target),
                DependencyType::New_,
                $location,
            ),
            fromMatch: (new LayerRegistry([new LayerDefinition('controller', new MembershipSpec(['App\\Controller']))]))->resolveAll($source)[0],
            toMatch: (new LayerRegistry([new LayerDefinition('repository', new MembershipSpec(['App\\Repository']))]))->resolveAll($target)[0],
            ownedTargets: [],
            ruleName: LayerViolationRule::NAME,
            severity: Severity::Warning,
            recommendation: 'Move the dependency behind an allowed boundary.',
        );

        $findingLocation = $finding->toFindings()[0]->location;

        self::assertNotSame($location, $findingLocation);
        self::assertSame('src/Controller.php', $findingLocation->file?->value());
        self::assertSame(12, $findingLocation->line);
    }

    #[Test]
    public function itRejectsADependencyLocationWithoutAnExactFileAndLine(): void
    {
        $source = SymbolPath::forClass('App\\Controller', 'Controller');
        $target = SymbolPath::forClass('App\\Repository', 'Repository');
        $location = new class implements DependencyLocationInterface {
            public function file(): ?RelativePath
            {
                return null;
            }

            public function line(): ?int
            {
                return null;
            }

            public function toString(): string
            {
                return '';
            }
        };
        $finding = new LayerViolationFinding(
            dependency: new Dependency(
                DeclarationPath::of($source, RelativePath::fromString('src/Controller.php'), DeclarationOrdinal::fromRank(0)),
                new LogicalClassPath($target),
                DependencyType::New_,
                $location,
            ),
            fromMatch: (new LayerRegistry([new LayerDefinition('controller', new MembershipSpec(['App\\Controller']))]))->resolveAll($source)[0],
            toMatch: (new LayerRegistry([new LayerDefinition('repository', new MembershipSpec(['App\\Repository']))]))->resolveAll($target)[0],
            ownedTargets: [],
            ruleName: LayerViolationRule::NAME,
            severity: Severity::Warning,
            recommendation: 'Move the dependency behind an allowed boundary.',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Layer violation findings require an exact dependency location.');
        $finding->toFindings();
    }

    /**
     * @param list<Dependency> $dependencies
     */
    private function buildGraph(array $dependencies): DependencyGraphInterface
    {
        $stub = self::createStub(DependencyGraphInterface::class);
        $stub->method('getAllDependencies')->willReturn($dependencies);

        return $stub;
    }

    private function buildDependency(
        string $sourceNamespace,
        string $sourceClass,
        string $targetNamespace,
        string $targetClass,
        DependencyType $type = DependencyType::New_,
    ): Dependency {
        return new Dependency(
            source: DeclarationPath::of(SymbolPath::forClass($sourceNamespace, $sourceClass), RelativePath::fromString('src/dummy.php'), DeclarationOrdinal::fromRank(0)),
            target: new LogicalClassPath(SymbolPath::forClass($targetNamespace, $targetClass)),
            type: $type,
            location: new Location(RelativePath::fromString('src/dummy.php'), 1),
        );
    }

    private function dependency(SymbolPath $source, SymbolPath $target, DependencyType $type, Location $location): Dependency
    {
        return new Dependency(
            DeclarationPath::of($source, $location->file ?? RelativePath::fromString('src/dummy.php'), DeclarationOrdinal::fromRank(0)),
            new LogicalClassPath($target),
            $type,
            $location,
        );
    }

    /**
     * Builds the rule under test wired against the test's scratch processor.
     * Tests call {@see buildContext()} next to prime the processor with the
     * architecture under test.
     */
    private function buildRule(LayerViolationOptions $options): LayerVerdicts
    {
        return new LayerVerdicts($options, $this->processor);
    }

    /**
     * Primes the per-test processor with the supplied architecture (if any)
     * and returns the {@see AnalysisContext} the rule consumes. Mirrors the
     * production flow: AnalysisPipeline prepares the processor before
     * calling LayerViolationRule::analyze().
     */
    private function buildContext(
        ?DependencyGraphInterface $graph,
        ?ArchitectureConfiguration $architecture,
        ?InMemoryMetricRepository $metrics = null,
    ): AnalysisContext {
        $repository = $metrics ?? new InMemoryMetricRepository();

        // Re-prime the same processor instance the rule was constructed with
        // so the prepared configuration is visible through that injection.
        ProcessorBuilder::prepared($architecture, $graph, $repository, $this->processor);

        return new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
        );
    }

    /**
     * Registers a class symbol in the metric repository so that
     * `metrics->all(SymbolLevel::Class_)` yields it.
     */
    private function registerClass(
        InMemoryMetricRepository $repo,
        string $namespace,
        string $class,
        ?string $file = null,
        int $startFilePos = 0,
    ): MetricSubject {
        $logical = SymbolPath::forClass($namespace, $class);
        $subject = MetricSubject::declaration(DeclarationPath::of($logical, RelativePath::fromString($file ?? \sprintf('src/%s.php', str_replace('\\', '/', $class))), DeclarationOrdinal::fromRank(0)));
        $repo->addSubject(
            $subject,
            new MetricBag(),
            $subject->declarationPath()?->file,
            1,
        );

        return $subject;
    }

    private function findDeclarationSubject(InMemoryMetricRepository $repository, SymbolPath $logical): MetricSubject
    {
        foreach ($repository->allDeclarations() as $declarationInfo) {
            if ($declarationInfo->subject?->toSymbolPath()->toCanonical() === $logical->toCanonical()) {
                return $declarationInfo->subject;
            }
        }

        self::fail('Expected an owned declaration for ' . $logical->toString());
    }

    /**
     * @param list<\Qualimetrix\Analysis\Finding\Contract\Finding> $findings
     *
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
     */
    private function filterByRule(array $findings, string $ruleName): array
    {
        return array_values(array_filter(
            $findings,
            static fn(\Qualimetrix\Analysis\Finding\Contract\Finding $v): bool => $v->ruleName === $ruleName,
        ));
    }
}
