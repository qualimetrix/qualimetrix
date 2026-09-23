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
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\DeclaredLayerReachability;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\AllowListBuilder;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\LayerVerdicts;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ProcessorBuilder;

/**
 * The last leg: an undecidable membership has to reach somebody who reads it.
 *
 * `architecture.coverage-gap` is that reader for the run, and the distinction
 * it carries is actionable rather than decorative — a class outside every layer
 * because no criterion caught it is closed by declaring a layer, and one
 * outside every layer because its inheritance chain leaves `paths:` is not.
 * Before the clause existed, both printed the same sentence, so the author who
 * followed the recommendation wrote a layer that changed nothing.
 */
#[CoversClass(DeclaredLayerReachability::class)]
final class UndecidableCoverageGapTest extends TestCase
{
    private ArchitecturePolicy $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitecturePolicy();
    }

    #[Test]
    public function itNamesTheUndecidedShareOfTheGap(): void
    {
        $finding = $this->coverageFinding(
            layerExtends: 'Vendor\\Lib\\Base',
            parent: 'Vendor\\Lib\\Middle',
        );

        self::assertNotNull($finding, 'The class is outside every layer, so the gap is reported.');
        // Two, not one: the vendor parent is an edge end this run resolved
        // against the same layer, and it carries the same silence — it is the
        // broader half of the mechanism, reaching the reader through the same
        // clause.
        self::assertStringContainsString('2 class(es) outside all declared layers.', $finding->message);
        self::assertStringContainsString('2 of them could not be decided', $finding->message);
        self::assertStringContainsString('App\\Web\\OrderController', $finding->message);
        self::assertStringContainsString('Vendor\\Lib\\Middle', $finding->message);
        self::assertStringContainsString('outside the analysed paths', $finding->message);
    }

    #[Test]
    public function itLeavesTheSentenceAloneWhenTheWholeGapWasDecided(): void
    {
        // Control, and the reason the clause is conditional: a project whose
        // classes are all decided must read exactly what it read before, or
        // every baseline and golden file in the world moves for nothing.
        $finding = $this->coverageFinding(
            layerExtends: 'App\\Web\\SomethingElse',
            parent: null,
        );

        self::assertNotNull($finding);
        self::assertStringEndsWith('1 class(es) outside all declared layers.', $finding->message);
        self::assertStringNotContainsString('could not be decided', $finding->message);
    }

    /**
     * Builds a one-class run whose single layer is declared through `extends`,
     * and returns the coverage-gap finding it produces.
     *
     * @param string $layerExtends The parent FQN the layer names.
     * @param string|null $parent The class's own parent, or null for a class
     *                            that extends nothing. A non-null value is NOT
     *                            the layer's target, so the chain has to be
     *                            walked one link further — which is exactly
     *                            where it leaves the analysed set.
     */
    private function coverageFinding(string $layerExtends, ?string $parent): ?Finding
    {
        $child = SymbolPath::forClass('App\\Web', 'OrderController');

        $architecture = new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('web', new MembershipSpec(extends: [$layerExtends]))]),
            AllowListBuilder::policyFromExactMap(['web' => []]),
            CoverageMode::Error,
        );

        $edges = $parent === null ? [] : [new Dependency(
            source: DeclarationPath::of($child, RelativePath::fromString('src/dummy.php'), DeclarationOrdinal::fromRank(0)),
            target: new LogicalClassPath(SymbolPath::fromClassFqn($parent)),
            type: DependencyType::Extends,
            location: new Location(RelativePath::fromString('src/dummy.php'), 1),
        )];

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getAllDependencies')->willReturn($edges);

        $repository = new InMemoryMetricRepository();
        $repository->add($child, new MetricBag(), RelativePath::fromString('src/dummy.php'), 1);

        ProcessorBuilder::prepared($architecture, $graph, $repository, $this->processor);

        $findings = (new LayerVerdicts(new LayerViolationOptions(), $this->processor))->analyze(new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
        ));

        foreach ($findings as $finding) {
            if ($finding->ruleName === LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME) {
                return $finding;
            }
        }

        return null;
    }
}
