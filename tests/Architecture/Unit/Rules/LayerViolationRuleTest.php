<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerPolicy;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Processing\ArchitectureProcessor;
use Qualimetrix\Architecture\Rules\LayerViolationFinding;
use Qualimetrix\Architecture\Rules\LayerViolationOptions;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Architecture\Rules\OwnedLayerTargets;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\CliAliasReader;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Architecture\Support\ProcessorBuilder;

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
    private ArchitectureProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitectureProcessor();
    }

    #[Test]
    public function metadataMatchesContract(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        self::assertSame('architecture.layer-violation', $rule->getName());
        self::assertSame(RuleCategory::Architecture, $rule->getCategory());
        self::assertSame([], $rule->requires());
        self::assertSame(LayerViolationOptions::class, LayerViolationRule::getOptionsClass());
        self::assertSame([
            'layer-violation' => 'enabled',
            'layer-violation-severity' => 'severity',
            'layer-violation-unreachable-layer-severity' => 'unreachable_layer_severity',
            'layer-violation-potential-shadow-severity' => 'potential_shadow_severity',
            'layer-violation-empty-template-severity' => 'empty_template_severity',
        ], CliAliasReader::read(LayerViolationRule::class));
        self::assertStringContainsString('layer', strtolower($rule->getDescription()));
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
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

    #[Test]
    public function nullArchitectureReturnsNoViolations(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Repository', 'UserRepository'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, null)));
    }

    #[Test]
    public function emptyArchitectureReturnsNoViolations(): void
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
        $violations = $rule->analyze($this->buildContext(null, $arch));
        // unreachable-layer fires because the controller layer matched nothing.
        self::assertCount(1, $violations);
        self::assertSame(LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME, $violations[0]->ruleName);
    }

    #[Test]
    public function allowedEdgeProducesNoViolation(): void
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

        $violations = $rule->analyze($this->buildContext($graph, $arch, $repo));

        // No layer violations; no unreachable-layer (both layers had hits); no shadow.
        self::assertSame([], $violations);
    }

    #[Test]
    public function forbiddenEdgeProducesViolationWithExpectedFields(): void
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

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(1, $violations);
        $violation = $violations[0];

        self::assertSame('architecture.layer-violation', $violation->ruleName);
        self::assertSame(Severity::Error, $violation->severity);
        self::assertSame($source, $violation->symbolPath);
        self::assertSame(
            $this->findDeclarationSubject($repo, $target)->toCanonical(),
            $violation->subject->toCanonical(),
        );
        self::assertNotNull($violation->occurrenceKey);
        self::assertSame($location, $violation->location);
        self::assertSame($target, $violation->dependencyTarget);
        self::assertSame(DependencyType::New_, $violation->dependencyType);
        self::assertStringContainsString('Layer "controller" must not depend on layer "repository"', $violation->message);
        self::assertStringContainsString('App\\Controller\\UserController', $violation->message);
        self::assertStringContainsString('App\\Repository\\UserRepository', $violation->message);

        $recommendation = $violation->recommendation;
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
        $policy = new \Qualimetrix\Architecture\Domain\Layer\LayerPolicy([
            new \Qualimetrix\Architecture\Domain\Allow\AllowListEntry(
                \Qualimetrix\Architecture\Domain\Allow\LayerSelector::exact('controller'),
                [new \Qualimetrix\Architecture\Domain\Allow\AllowTarget(
                    \Qualimetrix\Architecture\Domain\Allow\LayerSelector::glob('*-repository'),
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

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
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

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringContainsString(
            'Layer "core" is not allowed to depend on any other declared layer.',
            $recommendation,
        );
    }

    #[Test]
    public function eachUseSiteProducesItsOwnViolation(): void
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

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(2, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(20, $violations[1]->location->line);
        self::assertNotSame($violations[0]->occurrenceKey?->value, $violations[1]->occurrenceKey?->value);
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
        $violations = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([
                $this->dependency($source, $target, DependencyType::New_, new Location(RelativePath::fromString('src/Controller.php'), 10)),
                $this->dependency($source, $target, DependencyType::New_, new Location(RelativePath::fromString('src/Controller.php'), 20)),
            ]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(2, $violations);
        self::assertSame($violations[0]->occurrenceKey?->value, $violations[1]->occurrenceKey?->value);
        self::assertSame([10, 20], array_map(
            static fn(\Qualimetrix\Core\Violation\Violation $violation): ?int => $violation->location->line,
            $violations,
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

        $violations = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture)),
            LayerViolationRule::NAME,
        );

        self::assertCount(1, $violations);
        self::assertSame(MetricSubject::declaration($dependency->source)->toCanonical(), $violations[0]->subject->toCanonical());
    }

    #[Test]
    public function itProjectsOneViolationToTheOwnedTargetDeclaration(): void
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

        $violations = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(1, $violations);
        self::assertSame($targetSubject->toCanonical(), $violations[0]->subject->toCanonical());
        self::assertSame($dependency->sourceLogical(), $violations[0]->symbolPath);
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
        $firstSource = new DeclarationPath(
            $sourceLogical,
            RelativePath::fromString('src/ControllerFirst.php'),
            10,
        );
        $secondSource = new DeclarationPath(
            $sourceLogical,
            RelativePath::fromString('src/ControllerSecond.php'),
            20,
        );
        $firstSubject = MetricSubject::declaration($firstSource);
        $secondSubject = MetricSubject::declaration($secondSource);
        $dependencies = [
            new Dependency($firstSource, $target, DependencyType::New_, new Location(RelativePath::fromString('src/ControllerFirst.php'), 5)),
            new Dependency($secondSource, $target, DependencyType::New_, new Location(RelativePath::fromString('src/ControllerSecond.php'), 5)),
        ];

        $violations = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph($dependencies), $architecture)),
            LayerViolationRule::NAME,
        );
        self::assertSame([$firstSubject->toCanonical(), $secondSubject->toCanonical()], array_map(
            static fn(\Qualimetrix\Core\Violation\Violation $violation): string => $violation->subject->toCanonical(),
            $violations,
        ));

        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/ControllerFirst.php', [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Only the first exact source declaration is accepted.',
            line: 1,
            type: SuppressionType::Symbol,
            subject: $firstSubject,
            controlScope: ControlScope::Class_,
        )]);
        self::assertSame([false, true], array_map($filter->shouldInclude(...), $violations));
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

        $violations = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(2, $violations);
        self::assertSame(
            [$first->toCanonical(), $second->toCanonical()],
            array_map(static fn(\Qualimetrix\Core\Violation\Violation $violation): string => $violation->subject->toCanonical(), $violations),
        );
        self::assertNotSame($violations[0]->occurrenceKey?->value, $violations[1]->occurrenceKey?->value);
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

        $violations = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph($dependencies), $architecture, $repository)),
            LayerViolationRule::NAME,
        );

        self::assertCount(4, $violations);
        self::assertSame([
            'class:App\\Controller\\FirstController|' . $firstTarget->toCanonical(),
            'class:App\\Controller\\FirstController|' . $secondTarget->toCanonical(),
            'class:App\\Controller\\SecondController|' . $firstTarget->toCanonical(),
            'class:App\\Controller\\SecondController|' . $secondTarget->toCanonical(),
        ], array_map(
            static fn(\Qualimetrix\Core\Violation\Violation $violation): string => $violation->symbolPath->toCanonical() . '|' . $violation->subject->toCanonical(),
            $violations,
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
        $first = MetricSubject::declaration(new DeclarationPath(
            $target,
            RelativePath::fromString('src/RepositoryFirst.php'),
            10,
        ));
        $second = MetricSubject::declaration(new DeclarationPath(
            $target,
            RelativePath::fromString('src/RepositorySecond.php'),
            20,
        ));

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

        $fallbackViolations = $fallback->toViolations();
        $oneViolations = $one->toViolations();
        $manyViolations = $many->toViolations();

        self::assertSame(MetricSubject::declaration($dependency->source)->toCanonical(), $fallbackViolations[0]->subject->toCanonical());
        self::assertSame([$first->toCanonical()], array_map(
            static fn(\Qualimetrix\Core\Violation\Violation $violation): string => $violation->subject->toCanonical(),
            $oneViolations,
        ));
        self::assertSame([$first->toCanonical(), $second->toCanonical()], array_map(
            static fn(\Qualimetrix\Core\Violation\Violation $violation): string => $violation->subject->toCanonical(),
            $manyViolations,
        ));
        self::assertNotSame($manyViolations[0]->occurrenceKey?->value, $manyViolations[1]->occurrenceKey?->value);
        self::assertSame($dependency->location, $manyViolations[0]->location);
        self::assertSame($target, $manyViolations[0]->dependencyTarget);
        self::assertSame(DependencyType::New_, $manyViolations[0]->dependencyType);
        self::assertStringContainsString('Layer "controller" must not depend on layer "repository"', $manyViolations[0]->message);

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
        $sameEdgeViolation = $sameEdgeAtAnotherLocation->toViolations()[0];

        self::assertSame($oneViolations[0]->occurrenceKey?->value, $sameEdgeViolation->occurrenceKey?->value);
        self::assertSame(24, $sameEdgeViolation->location->line);
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
        $violations = $this->filterByRule(
            $rule->analyze($this->buildContext($this->buildGraph([$dependency]), $architecture, $repository)),
            LayerViolationRule::NAME,
        );
        self::assertCount(2, $violations);

        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/source.php', [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Source declaration is independently controlled.',
            line: 1,
            type: SuppressionType::Symbol,
            subject: $sourceSubject,
            controlScope: ControlScope::Class_,
        )]);
        self::assertSame([true, true], array_map($filter->shouldInclude(...), $violations));

        $filter->setSuppressions('src/RepositoryOne.php', [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Target declaration control is independent.',
            line: 1,
            type: SuppressionType::Symbol,
            subject: $firstTargetSubject,
            controlScope: ControlScope::Class_,
        )]);
        self::assertSame([false, true], array_map($filter->shouldInclude(...), $violations));

        $filter->setSuppressions('src/Controller.php', [new Suppression(
            rule: LayerViolationRule::NAME,
            reason: 'Physical use-site control applies to every projection.',
            line: 10,
            type: SuppressionType::NextLine,
        )]);
        self::assertSame([false, false], array_map($filter->shouldInclude(...), $violations));
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

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertSame([], $violations);
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

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertSame([], $violations);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $unreachable = $this->filterByRule($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertSame(Severity::Info, $unreachable[0]->severity);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $unreachable = $this->filterByRule($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
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

        $violations = $rule->analyze($this->buildContext($graph, $arch, $repo));

        $layerViolations = $this->filterByRule($violations, LayerViolationRule::NAME);
        self::assertCount(1, $layerViolations, 'layer-violation must still fire for the disallowed edge.');
        self::assertStringContainsString(
            'Layer "infrastructure" must not depend on layer "vendor-clickhouse"',
            $layerViolations[0]->message,
        );

        $unreachableLayerNames = array_map(
            static fn(\Qualimetrix\Core\Violation\Violation $v): string => self::extractLayerName($v->message),
            $this->filterByRule($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME),
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
    public function itAllowsConfiguringUnreachableLayerSeverity(): void
    {
        // A typo in `patterns:` (e.g. `App\Controler\**`) silently disables a
        // layer with no CLI-visible signal beyond the default Info severity.
        // Raising unreachableLayerSeverity to Error is exactly the escape
        // hatch this option exists for.
        $rule = $this->buildRule(new LayerViolationOptions(unreachableLayerSeverity: Severity::Error));

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller\\**']],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Service', 'UserService');

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $unreachable = $this->filterByRule($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertSame(Severity::Error, $unreachable[0]->severity);
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
        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $unreachable = $this->filterByRule($violations, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($violations, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow);
        self::assertSame(Severity::Info, $shadow[0]->severity);
        self::assertStringContainsString('"any-foo"', $shadow[0]->message);
        self::assertStringContainsString('"service"', $shadow[0]->message);
        self::assertStringContainsString('App\\Service\\Foo', $shadow[0]->message);
    }

    #[Test]
    public function itAllowsConfiguringPotentialShadowSeverity(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions(potentialShadowSeverity: Severity::Error));

        $arch = $this->buildArchitecture(
            layers: [
                'any-foo' => ['App\\**\\Foo'],
                'service' => ['App\\Service\\**'],
            ],
            allow: [],
        );

        $repo = new InMemoryMetricRepository();
        $this->registerClass($repo, 'App\\Service', 'Foo');

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($violations, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow);
        self::assertSame(Severity::Error, $shadow[0]->severity);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($violations, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, new InMemoryMetricRepository()));

        $shadow = $this->filterByRule($violations, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($violations, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($violations, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
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

        $violations = $rule->analyze($this->buildContext(null, $arch, $repo));

        $shadow = $this->filterByRule($violations, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
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

        $shadow1 = $this->filterByRule($run1, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        $shadow2 = $this->filterByRule($run2, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);

        $msgs1 = array_map(static fn($v) => $v->message, $shadow1);
        $msgs2 = array_map(static fn($v) => $v->message, $shadow2);

        self::assertSame($msgs1, $msgs2, 'Shadow diagnostics must be lexicographically deterministic across runs.');
    }

    // -------------------------------------------------------------------------
    // architecture.empty-template diagnostic
    // -------------------------------------------------------------------------

    #[Test]
    public function itDefaultsEmptyTemplateSeverityToWarning(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitectureWithEmptyTemplates(['domain-{module}']);

        $violations = $rule->analyze($this->buildContext(null, $arch));

        $emptyTemplate = $this->filterByRule($violations, LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME);
        self::assertCount(1, $emptyTemplate);
        self::assertSame(Severity::Warning, $emptyTemplate[0]->severity);
        self::assertStringContainsString('domain-{module}', $emptyTemplate[0]->message);
    }

    #[Test]
    public function itAllowsConfiguringEmptyTemplateSeverity(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions(emptyTemplateSeverity: Severity::Error));

        $arch = $this->buildArchitectureWithEmptyTemplates(['domain-{module}']);

        $violations = $rule->analyze($this->buildContext(null, $arch));

        $emptyTemplate = $this->filterByRule($violations, LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME);
        self::assertCount(1, $emptyTemplate);
        self::assertSame(Severity::Error, $emptyTemplate[0]->severity);
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
        $shadow1 = $this->filterByRule($run1, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadow1, 'First analyze() should produce one shadow diagnostic.');
        self::assertStringContainsString('1 class(es)', $shadow1[0]->message);

        // Context 2: empty repo — must NOT carry any state from run 1.
        $run2 = $rule->analyze($this->buildContext(null, $arch, new InMemoryMetricRepository()));
        $shadow2 = $this->filterByRule($run2, LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertSame([], $shadow2, 'Second analyze() with empty context must produce zero shadow diagnostics — hit counter/shadow evidence must not leak.');

        // unreachable-layer for the second run fires on BOTH layers (no classes
        // means no hits anywhere).
        $unreachable2 = $this->filterByRule($run2, LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
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
     * {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage} would
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
            source: new DeclarationPath(SymbolPath::forClass($sourceNamespace, $sourceClass), RelativePath::fromString('src/dummy.php'), 0),
            target: new LogicalClassPath(SymbolPath::forClass($targetNamespace, $targetClass)),
            type: $type,
            location: new Location(RelativePath::fromString('src/dummy.php'), 1),
        );
    }

    private function dependency(SymbolPath $source, SymbolPath $target, DependencyType $type, Location $location): Dependency
    {
        return new Dependency(
            new DeclarationPath($source, $location->file ?? RelativePath::fromString('src/dummy.php'), 0),
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
    private function buildRule(LayerViolationOptions $options): LayerViolationRule
    {
        return new LayerViolationRule($options, $this->processor);
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
     * `metrics->all(SymbolType::Class_)` yields it.
     */
    private function registerClass(
        InMemoryMetricRepository $repo,
        string $namespace,
        string $class,
        ?string $file = null,
        int $startFilePos = 0,
    ): MetricSubject {
        $logical = SymbolPath::forClass($namespace, $class);
        $subject = MetricSubject::declaration(new DeclarationPath(
            $logical,
            RelativePath::fromString($file ?? \sprintf('src/%s.php', str_replace('\\', '/', $class))),
            $startFilePos,
        ));
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
     * @param list<\Qualimetrix\Core\Violation\Violation> $violations
     *
     * @return list<\Qualimetrix\Core\Violation\Violation>
     */
    private function filterByRule(array $violations, string $ruleName): array
    {
        return array_values(array_filter(
            $violations,
            static fn(\Qualimetrix\Core\Violation\Violation $v): bool => $v->ruleName === $ruleName,
        ));
    }
}
