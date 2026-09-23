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
use Qualimetrix\Analysis\Finding\Contract\Severity;
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
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
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
    public function itGivesOnlyTheAnalysedClassAdviceWhenEveryUndecidedSymbolWasAnalysed(): void
    {
        // The child is analysed and undecided; its vendor parent is decided by
        // the later patterns layer. Advice about a symbol outside the analysed
        // paths applies to nothing here, so it must not be given.
        $finding = $this->coverageFindingFor(
            [
                new LayerDefinition('web', new MembershipSpec(extends: ['Vendor\\Lib\\Base'])),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        self::assertNotNull($finding);
        self::assertStringContainsString('1 of them could not be decided', $finding->message);
        $recommendation = (string) $finding->recommendation;
        self::assertStringContainsString('debug:layer-assignment', $recommendation);
        self::assertStringNotContainsString('patterns layer for its namespace', $recommendation);
    }

    #[Test]
    public function itGivesOnlyTheOutsideAdviceWhenEveryUndecidedSymbolIsOutsideThePaths(): void
    {
        // The child matches through its direct parent; only that parent, never
        // analysed, is undecided. `debug:layer-assignment` refuses a class the
        // run did not analyse, so pointing there would be advice nobody can
        // follow.
        $finding = $this->coverageFindingFor(
            [new LayerDefinition('web', new MembershipSpec(extends: ['Vendor\\Lib\\Middle']))],
            'Vendor\\Lib\\Middle',
        );

        self::assertNotNull($finding);
        self::assertStringContainsString('1 of them could not be decided', $finding->message);
        $recommendation = (string) $finding->recommendation;
        self::assertStringContainsString('patterns layer for its namespace', $recommendation);
        self::assertStringNotContainsString('debug:layer-assignment', $recommendation);
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
        // The doubt is counted here and published on its own channel; the gap
        // recommendation points there instead of restating its advice.
        self::assertStringContainsString('architecture.doubted-assignment', (string) $finding->recommendation);
    }

    #[Test]
    public function itLeavesAFullyCoveredRunWithoutAGapWhenOnlyAssignmentsAreInDoubt(): void
    {
        // Every end is assigned. A doubt is a statement about how sure the run
        // is of an assignment, not a hole in the declaration, so it must not
        // fail the run through a configuration-error channel — the published
        // shape that turned fully covered projects red on every vendor edge.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('web', new MembershipSpec(
                    patterns: ['App\\Web\\**'],
                    exclude: new ExcludeSpec(extends: ['Vendor\\Lib\\Base']),
                )),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        self::assertNull(self::findingOn($findings, LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itPublishesTheDoubtAsInformationWhenNothingIsOutsideEveryLayer(): void
    {
        // The half that keeps the cure from being silence: the count still
        // reaches the report, at a severity that never gates.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('web', new MembershipSpec(
                    patterns: ['App\\Web\\**'],
                    exclude: new ExcludeSpec(extends: ['Vendor\\Lib\\Base']),
                )),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        $doubt = self::findingOn($findings, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME);
        self::assertNotNull($doubt);
        self::assertSame(Severity::Info, $doubt->severity);
        self::assertStringContainsString('1 assigned symbol(s) rest on a layer the run could not fully decide', $doubt->message);
        self::assertStringContainsString('App\\Web\\OrderController', $doubt->message);
        $recommendation = (string) $doubt->recommendation;
        self::assertStringContainsString('debug:layer-assignment', $recommendation);
        // Only analysed classes are in doubt here, so the advice for a symbol
        // outside the analysed paths does not apply and is not given.
        self::assertStringNotContainsString('outside the analysed paths', $recommendation);
        self::assertStringNotContainsString('Declare layers covering', $recommendation);
    }

    #[Test]
    public function itAdvisesANamespaceLayerForADoubtedSymbolOutsideThePaths(): void
    {
        // A vendor end is in doubt because an earlier `extends` layer cannot
        // be answered about it. `debug:layer-assignment` refuses a class the
        // run did not analyse, so pointing there would be advice nobody can
        // follow; a patterns layer declared first is what settles it.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('controllers', new MembershipSpec(extends: ['Vendor\\Lib\\Middle'])),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        $doubt = self::findingOn($findings, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME);
        self::assertNotNull($doubt);
        self::assertStringContainsString('Vendor\\Lib\\Middle', $doubt->message);
        self::assertStringContainsString('1 outside the analysed paths', $doubt->message);
        $recommendation = (string) $doubt->recommendation;
        self::assertStringContainsString('patterns layer for its namespace declared before', $recommendation);
        self::assertStringNotContainsString('debug:layer-assignment', $recommendation);
        // "Outside the analysed paths" is a fact about the run, not about whose
        // code the symbol is: a run over one directory leaves the project's own
        // classes there too, and for them a patterns layer is a remodelling,
        // not the answer — analysing them is.
        self::assertStringContainsString('your own code', $recommendation);
        self::assertStringContainsString('widening paths', $recommendation);
    }

    #[Test]
    public function itPublishesTheDoubtWhateverTheCoverageMode(): void
    {
        // `coverage-gap: ignore` is the default. Gating the doubt on it left an
        // `exclude:` the run could not answer with no trace in `check` at all,
        // on exactly the configuration most projects run.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('web', new MembershipSpec(
                    patterns: ['App\\Web\\**'],
                    exclude: new ExcludeSpec(extends: ['Vendor\\Lib\\Base']),
                )),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
            CoverageMode::Ignore,
        );

        $doubt = self::findingOn($findings, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME);
        self::assertNotNull($doubt);
        self::assertSame(Severity::Info, $doubt->severity);
        self::assertNull(self::findingOn($findings, LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME));
        // The class walk has to book the analysed class whatever the mode: the
        // edge walk sees it too, and without the class walk's booking it would
        // be counted as a symbol the run never analysed.
        self::assertStringContainsString('(1 analysed class(es))', $doubt->message);
        self::assertStringContainsString('App\\Web\\OrderController', $doubt->message);
        self::assertStringContainsString('"web" (1 assigned in doubt)', $doubt->message);
    }

    #[Test]
    public function itNamesTheLayersThatCouldNotAnswerAndHowMuchEachLeftInDoubt(): void
    {
        // Two layers could not answer: `controllers` about the vendor parent
        // (never analysed), `models` about both ends. The reader has to know
        // which layer to settle, and for a symbol outside the paths nothing
        // but this finding names it.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('models', new MembershipSpec(extends: ['Vendor\\Lib\\Base'])),
                new LayerDefinition('controllers', new MembershipSpec(extends: ['Vendor\\Lib\\Middle'])),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        $doubt = self::findingOn($findings, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME);
        self::assertNotNull($doubt);
        self::assertStringContainsString('"models" (2 assigned in doubt)', $doubt->message);
        self::assertStringContainsString('"controllers" (1 assigned in doubt)', $doubt->message);
        self::assertStringContainsString('at most 10 of each kind', $doubt->message);
    }

    #[Test]
    public function itKeepsALayerThatCouldNotAnswerVisibleWhenItLeftSymbolsInNoLayer(): void
    {
        // No later layer catches the class, so the doubt is not about an
        // assignment but about the absence of one. `architecture.unreachable-layer`
        // may no longer say "matches no class" about this layer, and under the
        // default `coverage-gap: ignore` nothing else would name it.
        $findings = $this->findingsFor(
            [new LayerDefinition('controllers', new MembershipSpec(extends: ['Vendor\\Lib\\Base']))],
            'Vendor\\Lib\\Middle',
            CoverageMode::Ignore,
        );

        self::assertNull(self::findingOn($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
        $doubt = self::findingOn($findings, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME);
        self::assertNotNull($doubt);
        self::assertStringContainsString('2 symbol(s) are in no layer because a layer could not answer about them', $doubt->message);
        self::assertStringContainsString('"controllers" (2 in no layer)', $doubt->message);
        self::assertStringNotContainsString('assigned symbol(s)', $doubt->message);
    }

    #[Test]
    public function itDoesNotCallALayerUnreachableWhileTheRunCouldNotAnswerIt(): void
    {
        // `controllers` matched nothing because the run could not answer it,
        // not because its criteria match no class — the finding said the
        // latter, as a configuration error that fails the run. `ghost` is the
        // control: its criteria really match nothing, and that is still
        // reported.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('controllers', new MembershipSpec(extends: ['Vendor\\Lib\\Base'])),
                new LayerDefinition('app', new MembershipSpec(patterns: ['App\\**'])),
                new LayerDefinition('ghost', new MembershipSpec(patterns: ['Nowhere\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        $unreachable = self::findingsOn($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('Layer "ghost"', $unreachable[0]->message);
    }

    #[Test]
    public function itBuildsNoShadowFromAMatchWhoseExcludeCouldNotBeAnswered(): void
    {
        // `app` carves the repositories out with an `exclude:` the run cannot
        // answer. Had it answered "yes", `repos` would own the class: neither
        // "app shadows repos" nor "repos is unreachable" is a conclusion the
        // run reached.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('app', new MembershipSpec(
                    patterns: ['App\\**'],
                    exclude: new ExcludeSpec(extends: ['Vendor\\Lib\\Base']),
                )),
                new LayerDefinition('repos', new MembershipSpec(patterns: ['App\\Web\\**'])),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        self::assertNull(self::findingOn($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
        self::assertNull(self::findingOn($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
        self::assertNotNull(self::findingOn($findings, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME));
    }

    #[Test]
    public function itStillReportsTheShadowWhenTheExcludeAnswered(): void
    {
        // Control: the same carve-out over a class whose chain is complete.
        // The clause answered "no", `app` owns the class for certain, and the
        // narrower `repos` declared after it can never win.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('app', new MembershipSpec(
                    patterns: ['App\\**'],
                    exclude: new ExcludeSpec(extends: ['Vendor\\Lib\\Base']),
                )),
                new LayerDefinition('repos', new MembershipSpec(patterns: ['App\\Web\\**'])),
            ],
            null,
        );

        self::assertNotNull(self::findingOn($findings, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
        self::assertNotNull(self::findingOn($findings, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
        self::assertNull(self::findingOn($findings, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME));
    }

    #[Test]
    public function itCountsADoubtedEdgeEndWhoseOtherEndIsInNoLayer(): void
    {
        // The vendor end is assigned under an `exclude:` the run cannot answer
        // about it, and the only edge reaching it starts at a class no layer
        // claims. The doubt is a fact about the vendor end alone, so the other
        // end being unassigned must not hide it.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('vendor', new MembershipSpec(
                    patterns: ['Vendor\\**'],
                    exclude: new ExcludeSpec(extends: ['Some\\Other\\Base']),
                )),
            ],
            'Vendor\\Lib\\Middle',
        );

        $gap = self::findingOn($findings, LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME);
        self::assertNotNull($gap, 'The source class is outside every layer.');
        self::assertStringContainsString('1 assigned class(es) rest on a layer the run could not fully decide', $gap->message);
        self::assertStringContainsString('Vendor\\Lib\\Middle', $gap->message);
    }

    #[Test]
    public function itKeepsTheExcludeClauseThatCouldNotAnswerOutOfTheInertReport(): void
    {
        // The clause was never evaluated to an answer for the one class the
        // layer caught. "Removed no class — drop it" is a conclusion the run
        // did not reach, and the class it may have been written for is exactly
        // this one.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('web', new MembershipSpec(
                    patterns: ['App\\Web\\**'],
                    exclude: new ExcludeSpec(implements: ['App\\Testing\\Marker']),
                )),
                new LayerDefinition('vendor', new MembershipSpec(patterns: ['Vendor\\**'])),
            ],
            'Vendor\\Lib\\Middle',
        );

        self::assertNull(self::findingOn($findings, LayerViolationRule::UNMATCHED_EXCLUDE_NAME));
    }

    #[Test]
    public function itStillReportsAnExcludeClauseThatAnsweredNoEverywhere(): void
    {
        // Control: the same clause over a class whose chain is known to its
        // root. Every evaluation answered "no", so the clause is inert.
        $findings = $this->findingsFor(
            [
                new LayerDefinition('web', new MembershipSpec(
                    patterns: ['App\\Web\\**'],
                    exclude: new ExcludeSpec(implements: ['App\\Testing\\Marker']),
                )),
            ],
            null,
        );

        $inert = self::findingOn($findings, LayerViolationRule::UNMATCHED_EXCLUDE_NAME);
        self::assertNotNull($inert);
        self::assertStringContainsString('removed no class', $inert->message);
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
        return self::findingOn($this->findingsFor($layers, $parent), LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME);
    }

    /**
     * @param list<Finding> $findings
     */
    private static function findingOn(array $findings, string $channel): ?Finding
    {
        foreach ($findings as $finding) {
            if ($finding->ruleName === $channel) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private static function findingsOn(array $findings, string $channel): array
    {
        return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleName === $channel));
    }

    /**
     * @param list<LayerDefinition> $layers
     *
     * @return list<Finding>
     */
    private function findingsFor(array $layers, ?string $parent, CoverageMode $mode = CoverageMode::Error): array
    {
        $child = SymbolPath::forClass('App\\Web', 'OrderController');

        $allow = [];
        foreach ($layers as $layer) {
            $allow[$layer->name()] = [];
        }

        $architecture = new ArchitectureConfiguration(
            new LayerRegistry($layers),
            AllowListBuilder::policyFromExactMap($allow),
            $mode,
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

        return (new LayerVerdicts(new LayerViolationOptions(), $this->processor))->analyze(new AnalysisContext(
            metrics: $repository,
            dependencyGraph: $graph,
        ));
    }
}
