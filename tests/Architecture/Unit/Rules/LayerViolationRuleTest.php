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
use Qualimetrix\Architecture\Rules\LayerViolationOptions;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\CliAliasReader;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Architecture\Support\ProcessorBuilder;

#[CoversClass(LayerViolationRule::class)]
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
        self::assertSame(['layer-violation' => 'enabled'], CliAliasReader::read(LayerViolationRule::class));
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
            new Dependency($source, $target, DependencyType::New_, $location),
        ]);

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(1, $violations);
        $violation = $violations[0];

        self::assertSame('architecture.layer-violation', $violation->ruleName);
        self::assertSame(Severity::Error, $violation->severity);
        self::assertSame($source, $violation->symbolPath);
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
            new Dependency($source, $target, DependencyType::New_, new Location(RelativePath::fromString('a.php'), 10)),
            new Dependency($source, $target, DependencyType::TypeHint, new Location(RelativePath::fromString('a.php'), 20)),
        ]);

        $violations = $this->filterByRule($rule->analyze($this->buildContext($graph, $arch, $repo)), LayerViolationRule::NAME);

        self::assertCount(2, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(20, $violations[1]->location->line);
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
            source: SymbolPath::forClass($sourceNamespace, $sourceClass),
            target: SymbolPath::forClass($targetNamespace, $targetClass),
            type: $type,
            location: new Location(RelativePath::fromString('src/dummy.php'), 1),
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
    private function registerClass(InMemoryMetricRepository $repo, string $namespace, string $class): void
    {
        $repo->add(
            SymbolPath::forClass($namespace, $class),
            new MetricBag(),
            \sprintf('src/%s.php', str_replace('\\', '/', $class)),
            1,
        );
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
