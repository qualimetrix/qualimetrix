<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Processing\ArchitectureProcessor;
use Qualimetrix\Architecture\Rules\LayerViolationOptions;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Tests\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Architecture\Support\ProcessorBuilder;
use ReflectionMethod;

#[CoversClass(LayerViolationRule::class)]
final class CoverageDiagnosticsTest extends TestCase
{
    private ArchitectureProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitectureProcessor();
    }

    #[Test]
    public function ignoreModeProducesNoDiagnosticEvenWithManyUnmatchedEdges(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Ignore,
        );

        $graph = $this->buildGraph([
            // Both ends out of layer
            $this->buildDependency('Vendor\\Foo', 'A', 'Vendor\\Bar', 'B'),
            // Source out of layer
            $this->buildDependency('Vendor\\Foo', 'A', 'App\\Controller', 'C'),
            // Target out of layer
            $this->buildDependency('App\\Controller', 'C', 'Vendor\\Bar', 'B'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        // Scope: this test is about coverage-mode behaviour. Filter to just
        // coverage diagnostics (the unreachable-layer diagnostic may also fire
        // because we register no classes, but that is exercised in the
        // LayerViolationRule test suite).
        self::assertSame([], $this->filterCoverageDiagnostics($violations));
    }

    #[Test]
    public function warnModeEmitsSingleDiagnosticWithWarningSeverity(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Warn,
        );

        $graph = $this->buildGraph([
            // Source unmatched, target matched
            $this->buildDependency('Vendor\\Foo', 'A', 'App\\Controller', 'C'),
            // Source matched, target unmatched
            $this->buildDependency('App\\Controller', 'C', 'Vendor\\Bar', 'B'),
            // Both unmatched
            $this->buildDependency('Vendor\\Foo', 'A', 'Vendor\\Bar', 'B'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        $diagnostics = $this->filterCoverageDiagnostics($violations);
        self::assertCount(1, $diagnostics);

        $diagnostic = $diagnostics[0];
        self::assertSame('architecture.coverage', $diagnostic->ruleName);
        self::assertSame('architecture.coverage', $diagnostic->violationCode);
        self::assertSame(Severity::Warning, $diagnostic->severity);
        self::assertStringContainsString('Architecture coverage:', $diagnostic->message);
        self::assertStringContainsString('2 edge(s) with unmatched source layer', $diagnostic->message);
        self::assertStringContainsString('2 edge(s) with unmatched target layer', $diagnostic->message);
        // Two distinct out-of-layer FQNs across the three edges (Vendor\Foo\A, Vendor\Bar\B) — dedup is intentional.
        self::assertStringContainsString('2 class(es) outside all declared layers', $diagnostic->message);
    }

    #[Test]
    public function errorModeEmitsDiagnosticWithErrorSeverity(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Error,
        );

        $graph = $this->buildGraph([
            $this->buildDependency('Vendor\\Foo', 'A', 'App\\Controller', 'C'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        $diagnostics = $this->filterCoverageDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
    }

    #[Test]
    public function noDiagnosticEmittedWhenAllEdgesAreFullyClassified(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'service' => ['App\\Service'],
            ],
            allow: ['controller' => ['service']],
            coverage: CoverageMode::Warn,
        );

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'A', 'App\\Service', 'B'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        self::assertSame([], $this->filterCoverageDiagnostics($violations));
    }

    #[Test]
    public function diagnosticRecommendationListsUpToTenSampleClassesAlphabetically(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Warn,
        );

        // 12 distinct unmatched target classes — diagnostic must show 10 + "...and 2 more"
        $dependencies = [];
        for ($i = 1; $i <= 12; $i++) {
            $dependencies[] = $this->buildDependency(
                'App\\Controller',
                'C',
                'Vendor\\Pkg',
                \sprintf('Class%02d', $i),
            );
        }

        $violations = $rule->analyze($this->buildContext($this->buildGraph($dependencies), $arch));

        $diagnostics = $this->filterCoverageDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        $recommendation = $diagnostics[0]->recommendation;
        self::assertNotNull($recommendation);

        // Confirm alphabetical order in the sample
        $classListStart = strpos($recommendation, 'Class01');
        self::assertIsInt($classListStart);
        $classListSnippet = substr($recommendation, $classListStart);
        self::assertStringStartsWith('Class01', $classListSnippet);
        self::assertStringContainsString('Class10', $recommendation);
        self::assertStringNotContainsString('Class11', $recommendation);
        self::assertStringNotContainsString('Class12', $recommendation);
        self::assertStringContainsString('...and 2 more', $recommendation);
    }

    #[Test]
    public function diagnosticAccompaniesRealViolationsWhenForbiddenEdgesAreMixedIn(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());

        $arch = $this->buildArchitecture(
            layers: [
                'controller' => ['App\\Controller'],
                'repository' => ['App\\Repository'],
            ],
            allow: ['controller' => []],
            coverage: CoverageMode::Warn,
        );

        $graph = $this->buildGraph([
            // Real forbidden edge
            $this->buildDependency('App\\Controller', 'C', 'App\\Repository', 'R'),
            // Unmatched edge
            $this->buildDependency('App\\Controller', 'C', 'Vendor\\Foo', 'F'),
        ]);

        $violations = $rule->analyze($this->buildContext($graph, $arch));

        $layerViolations = $this->filterLayerViolations($violations);
        $diagnostics = $this->filterCoverageDiagnostics($violations);

        self::assertCount(1, $layerViolations);
        self::assertCount(1, $diagnostics);
    }

    #[Test]
    public function itReportsAnIsolatedUncoveredClassWithoutDependencyEdges(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Error,
        );

        $violations = $rule->analyze($this->buildContext(
            $this->buildGraph([]),
            $arch,
            ['App\\Unowned\\LonelyClass'],
        ));

        $diagnostics = $this->filterCoverageDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
        self::assertStringContainsString('0 edge(s) with unmatched source layer', $diagnostics[0]->message);
        self::assertStringContainsString('0 edge(s) with unmatched target layer', $diagnostics[0]->message);
        self::assertStringContainsString('1 class(es) outside all declared layers', $diagnostics[0]->message);
        self::assertStringContainsString('App\\Unowned\\LonelyClass', (string) $diagnostics[0]->recommendation);
    }

    #[Test]
    public function itDoesNotReportAnOwnedIsolatedClassWithoutDependencyEdges(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Error,
        );

        $violations = $rule->analyze($this->buildContext(
            $this->buildGraph([]),
            $arch,
            ['App\\Controller\\LonelyController'],
        ));

        self::assertSame([], $this->filterCoverageDiagnostics($violations));
    }

    #[Test]
    public function itDeduplicatesAnUncoveredClassSeenInTheRepositoryAndDependencyGraph(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Error,
        );
        $graph = $this->buildGraph([
            $this->buildDependency('App\\Unowned', 'SharedClass', 'App\\Controller', 'Controller'),
        ]);

        $violations = $rule->analyze($this->buildContext(
            $graph,
            $arch,
            ['App\\Unowned\\SharedClass', 'App\\Controller\\Controller'],
        ));

        $diagnostics = $this->filterCoverageDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString('1 edge(s) with unmatched source layer', $diagnostics[0]->message);
        self::assertStringContainsString('1 class(es) outside all declared layers', $diagnostics[0]->message);
        self::assertSame(1, substr_count((string) $diagnostics[0]->recommendation, 'App\\Unowned\\SharedClass'));
    }

    #[Test]
    public function itKeepsIgnoreModeForAnIsolatedUncoveredClass(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $arch = $this->buildArchitecture(
            layers: ['controller' => ['App\\Controller']],
            allow: ['controller' => []],
            coverage: CoverageMode::Ignore,
        );

        $violations = $rule->analyze($this->buildContext(
            $this->buildGraph([]),
            $arch,
            ['App\\Unowned\\LonelyClass'],
        ));

        self::assertSame([], $this->filterCoverageDiagnostics($violations));
    }

    #[Test]
    public function itSkipsUncoveredClassMaterializationInIgnoreModeWithoutSkippingLayerEvidence(): void
    {
        $rule = $this->buildRule(new LayerViolationOptions());
        $arch = $this->buildArchitecture(
            layers: [
                'broad' => ['App\\**'],
                'narrow' => ['App\\Controller\\**'],
            ],
            allow: ['broad' => [], 'narrow' => []],
            coverage: CoverageMode::Ignore,
        );
        $context = $this->buildContext(
            $this->buildGraph([]),
            $arch,
            ['App\\Controller\\OwnedClass', 'Vendor\\Unowned\\LonelyClass'],
        );

        $collectClassEvidence = new ReflectionMethod($rule, 'collectClassEvidence');
        [$layerHits, $shadowEvidence, $uncoveredClasses] = $collectClassEvidence->invoke(
            $rule,
            $arch->registry(),
            $context,
            CoverageMode::Ignore,
        );

        self::assertSame(['broad' => 1, 'narrow' => 0], $layerHits);
        self::assertArrayHasKey('broad', $shadowEvidence);
        self::assertArrayHasKey('narrow', $shadowEvidence['broad']);
        self::assertSame([], $uncoveredClasses);
    }

    /**
     * @param array<string, list<string>> $layers
     * @param array<string, list<string>> $allow
     */
    private function buildArchitecture(array $layers, array $allow, CoverageMode $coverage): ArchitectureConfiguration
    {
        $definitions = [];
        foreach ($layers as $name => $patterns) {
            $definitions[] = new LayerDefinition($name, new MembershipSpec($patterns));
        }

        return new ArchitectureConfiguration(
            new LayerRegistry($definitions),
            AllowListBuilder::policyFromExactMap($allow),
            $coverage,
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

    private function buildRule(LayerViolationOptions $options): LayerViolationRule
    {
        return new LayerViolationRule($options, $this->processor);
    }

    /** @param list<string> $logicalClasses */
    private function buildContext(
        ?DependencyGraphInterface $graph,
        ?ArchitectureConfiguration $architecture,
        array $logicalClasses = [],
    ): AnalysisContext {
        $repository = new InMemoryMetricRepository();
        foreach ($logicalClasses as $logicalClass) {
            $repository->add(
                SymbolPath::fromClassFqn($logicalClass),
                new MetricBag(),
                RelativePath::fromString('src/dummy.php'),
                1,
            );
        }

        ProcessorBuilder::prepared($architecture, $graph, $repository, $this->processor);

        return new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
        );
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function filterCoverageDiagnostics(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $v): bool => $v->ruleName === LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME,
        ));
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function filterLayerViolations(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $v): bool => $v->ruleName === LayerViolationRule::NAME,
        ));
    }
}
