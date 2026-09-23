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
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
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
 * outside every layer because its inheritance chain leaves `paths:` is closed
 * by declaring one only as a guess: the class may belong to the layer that
 * could not answer. Before the clause existed, both printed the same sentence.
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
    public function itSaysWhatALaterLayerDoesWithTheUndecidedShare(): void
    {
        // A layer declared after the unanswered one does assign these classes —
        // `LayerRegistry::undecidedLayers()` keeps a later match on purpose —
        // so a text saying it will not sends the reader away from the one edit
        // that closes the gap, and hides that the edit closes it by guessing.
        $finding = $this->coverageFinding(
            layerExtends: 'Vendor\Lib\Base',
            parent: 'Vendor\Lib\Middle',
        );

        self::assertNotNull($finding);
        $recommendation = $finding->recommendation;
        self::assertNotNull($recommendation);
        self::assertStringNotContainsString('will not cover', $finding->message . ' ' . $recommendation);
        self::assertStringContainsString('declared after', $recommendation);
        self::assertStringContainsString('guess', $recommendation);
        self::assertStringContainsString('patterns layer', $recommendation);
        self::assertStringContainsString('debug:layer-assignment', $recommendation);
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

    #[Test]
    public function itNamesAnAssignmentAnUnanswerableExcludeLeftInDoubt(): void
    {
        // The class stays in `web` — its edges are judged — but whether the
        // clause that would remove it fires is unknown, and the gap report is
        // where that doubt is published.
        $finding = $this->coverageFinding(
            layerExtends: 'Vendor\\Lib\\Base',
            parent: 'Vendor\\Lib\\Middle',
            membership: new MembershipSpec(
                patterns: ['App\\Web\\**'],
                exclude: new ExcludeSpec(extends: ['Vendor\\Lib\\Base']),
            ),
        );

        self::assertNotNull($finding, 'A doubted assignment is reported even when nothing is outside every layer.');
        self::assertStringContainsString('1 assigned class(es) rest on a layer the run could not fully decide', $finding->message);
        self::assertStringContainsString('App\\Web\\OrderController', $finding->message);
        self::assertStringContainsString('debug:layer-assignment', (string) $finding->recommendation);
    }

    #[Test]
    public function itReportsADoubtedAssignmentWhenNothingIsOutsideEveryLayer(): void
    {
        // Every end is assigned, so the only thing to say is the doubt. A gap
        // report that spoke only about unassigned symbols would stay silent.
        $finding = $this->coverageFindingFor(
            [
                new LayerDefinition('web', new MembershipSpec(
                    patterns: ['App\\Web\\**'],
                    exclude: new ExcludeSpec(extends: ['Vendor\\Lib\\Base']),
                )),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        self::assertNotNull($finding);
        self::assertStringContainsString('0 class(es) outside all declared layers.', $finding->message);
        self::assertStringContainsString('1 assigned class(es) rest on a layer the run could not fully decide', $finding->message);
    }

    #[Test]
    public function itDoesNotDoubtAnAssignmentOverALayerDeclaredAfterIt(): void
    {
        // First match wins, so a layer declared after the assigned one could
        // not have owned the class whatever it would have answered.
        $finding = $this->coverageFindingFor(
            [
                new LayerDefinition('web', new MembershipSpec(patterns: ['App\\Web\\**'])),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
                new LayerDefinition('vendorish', new MembershipSpec(extends: ['Vendor\\Lib\\Base'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        self::assertNull($finding, 'Every end is assigned and no assignment is in doubt.');
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
    private function coverageFinding(string $layerExtends, ?string $parent, ?MembershipSpec $membership = null): ?Finding
    {
        return $this->coverageFindingFor(
            [new LayerDefinition('web', $membership ?? new MembershipSpec(extends: [$layerExtends]))],
            $parent,
        );
    }

    /**
     * @param list<LayerDefinition> $layers
     */
    private function coverageFindingFor(array $layers, ?string $parent): ?Finding
    {
        $child = SymbolPath::forClass('App\\Web', 'OrderController');

        $allow = [];
        foreach ($layers as $layer) {
            $allow[$layer->name()] = [];
        }

        $architecture = new ArchitectureConfiguration(
            new LayerRegistry($layers),
            AllowListBuilder::policyFromExactMap($allow),
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
        $graph->method('getDeclarationDependencies')->willReturn($edges);

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
