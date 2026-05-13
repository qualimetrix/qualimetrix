<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Architecture\LayerViolationOptions;
use Qualimetrix\Rules\Architecture\LayerViolationRule;

#[CoversClass(LayerViolationRule::class)]
final class LayerViolationRuleTest extends TestCase
{
    #[Test]
    public function metadataMatchesContract(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

        self::assertSame('architecture.layer-violation', $rule->getName());
        self::assertSame(RuleCategory::Architecture, $rule->getCategory());
        self::assertSame([], $rule->requires());
        self::assertSame(LayerViolationOptions::class, LayerViolationRule::getOptionsClass());
        self::assertSame(['layer-violation' => 'enabled'], LayerViolationRule::getCliAliases());
        self::assertStringContainsString('layer', strtolower($rule->getDescription()));
    }

    #[Test]
    public function disabledRuleReturnsNoViolations(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions(enabled: false));

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
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Repository', 'UserRepository'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, null)));
    }

    #[Test]
    public function emptyArchitectureReturnsNoViolations(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

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
    public function nullDependencyGraphReturnsNoViolations(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: [],
        );

        self::assertSame([], $rule->analyze($this->buildContext(null, $arch)));
    }

    #[Test]
    public function allowedEdgeProducesNoViolation(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'service' => ['App\\Service'],
            ],
            allow: ['controller' => ['service']],
        );

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'App\\Service', 'UserService'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, $arch)));
    }

    #[Test]
    public function forbiddenEdgeProducesViolationWithExpectedFields(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions(severity: Severity::Error));

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

        $source = SymbolPath::forClass('App\\Controller', 'UserController');
        $target = SymbolPath::forClass('App\\Repository', 'UserRepository');
        $location = new Location('src/Controller/UserController.php', 42, precise: true);

        $graph = $this->buildGraph([
            new Dependency($source, $target, DependencyType::New_, $location),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        self::assertCount(1, $violations);
        $violation = $violations[0];

        self::assertSame('architecture.layer-violation', $violation->ruleName);
        self::assertSame('architecture.layer-violation', $violation->violationCode);
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
        self::assertSame('App\\Controller\\UserController', $decoded['source']);
        self::assertSame('App\\Repository\\UserRepository', $decoded['target']);
        self::assertSame(DependencyType::New_->value, $decoded['type']);
    }

    #[Test]
    public function recommendationFallsBackToEmptyAllowListWording(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'core' => ['App\\Core'],
                'service' => ['App\\Service'],
            ],
            allow: [
                'core' => [], // core may not depend on anything else
            ],
        );

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Core', 'Kernel', 'App\\Service', 'UserService'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

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
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'repository' => ['App\\Repository'],
            ],
            allow: ['controller' => []],
        );

        $source = SymbolPath::forClass('App\\Controller', 'UserController');
        $target = SymbolPath::forClass('App\\Repository', 'UserRepository');

        $graph = $this->buildGraph([
            new Dependency($source, $target, DependencyType::New_, new Location('a.php', 10)),
            new Dependency($source, $target, DependencyType::TypeHint, new Location('a.php', 20)),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        self::assertCount(2, $violations);
        self::assertSame(10, $violations[0]->location->line);
        self::assertSame(20, $violations[1]->location->line);
        self::assertSame(DependencyType::New_, $violations[0]->dependencyType);
        self::assertSame(DependencyType::TypeHint, $violations[1]->dependencyType);
    }

    #[Test]
    public function unmatchedSourceLayerEdgeIsIgnored(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['repository' => ['App\\Repository']],
            allow: ['repository' => []],
        );

        // Source is outside any declared layer
        $graph = $this->buildGraph([
            $this->buildDependency('Other\\Vendor', 'Helper', 'App\\Repository', 'UserRepository'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, $arch)));
    }

    #[Test]
    public function unmatchedTargetLayerEdgeIsIgnored(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
        );

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'Other\\Vendor', 'Helper'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, $arch)));
    }

    #[Test]
    public function sameLayerEdgeIsIgnored(): void
    {
        $rule = new LayerViolationRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['service' => ['App\\Service']],
            allow: [], // no inter-layer allow needed
        );

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Service', 'Foo', 'App\\Service', 'Bar'),
        ]);

        self::assertSame([], $rule->analyze($this->buildContext($graph, $arch)));
    }

    #[Test]
    public function layerCollisionDuringResolutionProducesDedicatedDiagnostic(): void
    {
        // Two layers, identical specificity. Both patterns equally specific → collision when resolving.
        $arch = new ArchitectureConfiguration(
            new LayerRegistry([
                new LayerDefinition('left', ['App\\Shared']),
                new LayerDefinition('right', ['App\\Shared']),
            ]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        $rule = new LayerViolationRule(new LayerViolationOptions());

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Shared', 'Foo', 'App\\Shared', 'Bar'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        // No layer-violation reported (edge dropped), but a dedicated collision diagnostic
        // surfaces the configuration error so it does not stay invisible.
        $layerViolations = array_values(array_filter(
            $violations,
            static fn($v) => $v->ruleName === LayerViolationRule::NAME,
        ));
        self::assertSame([], $layerViolations);

        $collisionDiagnostics = array_values(array_filter(
            $violations,
            static fn($v) => $v->ruleName === LayerViolationRule::COLLISION_DIAGNOSTIC_NAME,
        ));
        self::assertNotEmpty($collisionDiagnostics);
        self::assertSame(Severity::Error, $collisionDiagnostics[0]->severity);
        self::assertStringContainsString('left', $collisionDiagnostics[0]->message);
        self::assertStringContainsString('right', $collisionDiagnostics[0]->message);
        self::assertStringContainsString('App\\Shared\\Foo', $collisionDiagnostics[0]->message);
    }

    #[Test]
    public function layerCollisionDiagnosticsAreDeduplicatedAcrossEdges(): void
    {
        $arch = new ArchitectureConfiguration(
            new LayerRegistry([
                new LayerDefinition('left', ['App\\Shared']),
                new LayerDefinition('right', ['App\\Shared']),
            ]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );

        $rule = new LayerViolationRule(new LayerViolationOptions());

        $graph = $this->buildGraph([
            // Same ambiguous source class on multiple edges
            $this->buildDependency('App\\Shared', 'Foo', 'Other\\X', 'A'),
            $this->buildDependency('App\\Shared', 'Foo', 'Other\\Y', 'B'),
            $this->buildDependency('App\\Shared', 'Foo', 'Other\\Z', 'C'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        $collisionDiagnostics = array_values(array_filter(
            $violations,
            static fn($v) => $v->ruleName === LayerViolationRule::COLLISION_DIAGNOSTIC_NAME,
        ));
        self::assertCount(1, $collisionDiagnostics, 'Same ambiguous class on N edges must yield ONE diagnostic.');
    }

    /**
     * @param array<string, list<string>> $layers
     * @param array<string, list<string>> $allow
     */
    private function buildArchitecture(array $layers, array $allow): ArchitectureConfiguration
    {
        $definitions = [];
        foreach ($layers as $name => $patterns) {
            $definitions[] = new LayerDefinition($name, $patterns);
        }

        return new ArchitectureConfiguration(
            new LayerRegistry($definitions),
            new LayerPolicy($allow),
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
            location: new Location('src/dummy.php', 1),
        );
    }

    private function buildContext(?DependencyGraphInterface $graph, ?ArchitectureConfiguration $architecture): AnalysisContext
    {
        return new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            dependencyGraph: $graph,
            architecture: $architecture,
        );
    }
}
