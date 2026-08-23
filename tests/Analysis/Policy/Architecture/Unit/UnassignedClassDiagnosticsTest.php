<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassMode;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\LayerVerdicts;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ProcessorBuilder;

/**
 * `architecture.unassigned-class` — the gate "every declaration I analysed is
 * assigned to a layer", which `architecture.coverage` cannot express because
 * it also counts dependency-edge ends outside the analysed set.
 */
#[CoversClass(LayerViolationRule::class)]
final class UnassignedClassDiagnosticsTest extends TestCase
{
    private ArchitecturePolicy $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitecturePolicy();
    }

    #[Test]
    public function itEmitsNothingWhenTheGateIsLeftAtItsDefault(): void
    {
        $rule = new LayerVerdicts(new LayerViolationOptions(), $this->processor);
        $architecture = $this->buildArchitecture(CoverageMode::Error);

        $violations = $rule->analyze($this->buildContext(
            $architecture,
            $this->buildGraph([]),
            ['App\\Unowned\\Lonely'],
        ));

        self::assertSame([], $this->unassignedDiagnostics($violations));
    }

    /**
     * The whole point of the separate gate: the project that suffers from
     * vendor edge ends keeps `coverage: ignore`, so evidence collection must
     * not hang off the coverage mode.
     */
    #[Test]
    public function itCollectsEvidenceWhenCoverageIsIgnoredAndOnlyTheGateIsOn(): void
    {
        $rule = new LayerVerdicts(
            new LayerViolationOptions(unassignedClass: UnassignedClassMode::Warn),
            $this->processor,
        );
        $architecture = $this->buildArchitecture(CoverageMode::Ignore);

        $violations = $rule->analyze($this->buildContext(
            $architecture,
            $this->buildGraph([]),
            ['App\\Controller\\UserController', 'App\\Unowned\\Lonely'],
        ));

        $diagnostics = $this->unassignedDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        self::assertSame(1, $diagnostics[0]->metricValue);
        self::assertStringContainsString('App\\Unowned\\Lonely', (string) $diagnostics[0]->recommendation);
        self::assertSame([], $this->coverageDiagnostics($violations));
    }

    /**
     * A dependency into a class outside the analysed set is what drowns
     * `architecture.coverage`; the count reported here must not move because
     * of it.
     */
    #[Test]
    public function itIgnoresDependencyEdgeEndsOutsideTheAnalysedSet(): void
    {
        $rule = new LayerVerdicts(
            new LayerViolationOptions(unassignedClass: UnassignedClassMode::Warn),
            $this->processor,
        );
        $architecture = $this->buildArchitecture(CoverageMode::Ignore);

        $graph = $this->buildGraph([
            $this->buildDependency('App\\Controller', 'UserController', 'Symfony\\Component\\Console', 'Command'),
            $this->buildDependency('App\\Unowned', 'Lonely', 'PHPUnit\\Framework', 'TestCase'),
        ]);

        $violations = $rule->analyze($this->buildContext(
            $architecture,
            $graph,
            ['App\\Controller\\UserController', 'App\\Unowned\\Lonely'],
        ));

        $diagnostics = $this->unassignedDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        self::assertSame(1, $diagnostics[0]->metricValue);

        $rendered = $diagnostics[0]->message . ' ' . (string) $diagnostics[0]->recommendation;
        self::assertStringNotContainsString('Symfony', $rendered);
        self::assertStringNotContainsString('PHPUnit', $rendered);
    }

    #[Test]
    public function itEmitsNothingWhenEveryAnalysedDeclarationIsAssigned(): void
    {
        $rule = new LayerVerdicts(
            new LayerViolationOptions(unassignedClass: UnassignedClassMode::Error),
            $this->processor,
        );
        $architecture = $this->buildArchitecture(CoverageMode::Ignore);

        $violations = $rule->analyze($this->buildContext(
            $architecture,
            $this->buildGraph([$this->buildDependency('App\\Controller', 'UserController', 'Vendor\\Pkg', 'Thing')]),
            ['App\\Controller\\UserController'],
        ));

        self::assertSame([], $this->unassignedDiagnostics($violations));
    }

    #[Test]
    public function itEmitsNothingWhenNoDeclarationWasAnalysedAtAll(): void
    {
        $rule = new LayerVerdicts(
            new LayerViolationOptions(unassignedClass: UnassignedClassMode::Error),
            $this->processor,
        );
        $architecture = $this->buildArchitecture(CoverageMode::Ignore);

        $violations = $rule->analyze($this->buildContext($architecture, $this->buildGraph([]), []));

        self::assertSame([], $this->unassignedDiagnostics($violations));
    }

    #[Test]
    public function itReportsTheAbsoluteCountAndKeepsTheShareInTheMessageOnly(): void
    {
        $rule = new LayerVerdicts(
            new LayerViolationOptions(unassignedClass: UnassignedClassMode::Warn),
            $this->processor,
        );
        $architecture = $this->buildArchitecture(CoverageMode::Ignore);

        $violations = $rule->analyze($this->buildContext(
            $architecture,
            $this->buildGraph([]),
            [
                'App\\Controller\\UserController',
                'App\\Unowned\\One',
                'App\\Unowned\\Two',
                'App\\Unowned\\Three',
            ],
        ));

        $diagnostics = $this->unassignedDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        self::assertSame(3, $diagnostics[0]->metricValue);
        self::assertSame(Severity::Warning, $diagnostics[0]->severity);
        self::assertStringContainsString('3 of 4 analysed class-like declaration(s) (75.0%)', $diagnostics[0]->message);
    }

    #[Test]
    public function itMirrorsTheModeNameInTheReportedSeverity(): void
    {
        $rule = new LayerVerdicts(
            new LayerViolationOptions(unassignedClass: UnassignedClassMode::Error),
            $this->processor,
        );
        $architecture = $this->buildArchitecture(CoverageMode::Ignore);

        $violations = $rule->analyze($this->buildContext(
            $architecture,
            $this->buildGraph([]),
            ['App\\Unowned\\Lonely'],
        ));

        $diagnostics = $this->unassignedDiagnostics($violations);
        self::assertCount(1, $diagnostics);
        self::assertSame(Severity::Error, $diagnostics[0]->severity);
    }

    /**
     * The only magnitude channel of this rule, and the only one a project may
     * accept into a baseline — both are deliberate, so both are pinned.
     */
    #[Test]
    public function itDeclaresTheChannelAsAMagnitudeThatIsNotAConfigurationError(): void
    {
        $declaration = LayerViolationRule::channelDeclarations()['architecture.unassigned-class#architecture.unassigned-class']
            ?? null;

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Higher, $declaration->direction);
        self::assertFalse($declaration->isConfigurationError());
    }

    private function buildArchitecture(CoverageMode $coverage): ArchitectureConfiguration
    {
        return new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('controller', new MembershipSpec(['App\\Controller\\**']))]),
            AllowListBuilder::policyFromExactMap(['controller' => []]),
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
    ): Dependency {
        return new Dependency(
            source: DeclarationPath::of(SymbolPath::forClass($sourceNamespace, $sourceClass), RelativePath::fromString('src/dummy.php'), DeclarationOrdinal::fromRank(0)),
            target: new LogicalClassPath(SymbolPath::forClass($targetNamespace, $targetClass)),
            type: DependencyType::New_,
            location: new Location(RelativePath::fromString('src/dummy.php'), 1),
        );
    }

    /** @param list<string> $logicalClasses */
    private function buildContext(
        ArchitectureConfiguration $architecture,
        DependencyGraphInterface $graph,
        array $logicalClasses,
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

        return new AnalysisContext(metrics: $repository, dependencyGraph: $graph);
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function unassignedDiagnostics(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $v): bool => $v->ruleName === LayerViolationRule::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
        ));
    }

    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function coverageDiagnostics(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            static fn(Violation $v): bool => $v->ruleName === LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME,
        ));
    }
}
