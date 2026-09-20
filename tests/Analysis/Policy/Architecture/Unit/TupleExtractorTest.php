<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Processing;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\TupleExtractor;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerCriteriaMatcher;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

/**
 * Pins the behavior of {@see TupleExtractor} extracted from
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage} during
 * Phase 4.1 of the remediation (ADR 0008). The {@code LayerExpansionStageTest}
 * still covers the end-to-end orchestration; this test focuses on the helper
 * surface in isolation.
 */
#[CoversClass(TupleExtractor::class)]
#[CoversClass(LayerCriteriaMatcher::class)]
final class TupleExtractorTest extends TestCase
{
    private TupleExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TupleExtractor();
    }

    #[Test]
    public function itRefusesAGraphBackedCriterionWhenTheFactoryIsUnbound(): void
    {
        // The defect this guard exists for lived here: observation under
        // `match: all` asking an unbound factory what a class extends, being
        // told "nothing", and expanding the template to zero layers. The
        // helper below binds a graph, so this case builds its own set.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                extends: ['App\\Domain\\AggregateRoot'],
                mode: MatchMode::All,
            ),
        );

        $unbound = new ClassSet(
            [SymbolPath::forClass('App\\Module\\Order\\Domain', 'Order')],
            new ClassContextFactory(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Layer criteria extends');

        $this->extractor->collect($template, $unbound);
    }

    #[Test]
    public function itObservesAPatternOnlyTemplateWithAnUnboundFactory(): void
    {
        // The other half: a template that asks nothing of the graph must keep
        // working unbound, or the guard would have outlawed the whole mode.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $unbound = new ClassSet(
            [SymbolPath::forClass('App\\Module\\Order\\Domain', 'Order')],
            new ClassContextFactory(),
        );

        self::assertSame([['module' => 'Order']], $this->extractor->collect($template, $unbound));
    }

    #[Test]
    public function itDedupesAndLexicallySortsTuplesForASingleVariableTemplate(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',
            'App\\Module\\Audit\\Domain\\B',
            'App\\Module\\Order\\Domain\\C',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame(
            [
                ['module' => 'Audit'],
                ['module' => 'Order'],
            ],
            $tuples,
        );
    }

    #[Test]
    public function itIgnoresATrailingBackslashInANonCaptureFilter(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: [
                'App\\Domain\\',
                'App\\Domain\\{module}\\Entity\\**',
            ]),
        );

        $classes = self::classSet([
            'App\\Domain\\Order\\Entity\\Customer',
            'App\\DomainBus\\Order\\Entity\\Ignored',
        ]);

        self::assertSame([['module' => 'Order']], $this->extractor->collect($template, $classes));
    }

    #[Test]
    public function itObservesOnlyTheActualCombinationsForAMultiVariableTemplate(): void
    {
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(patterns: ['App\\{tenant}\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\AcmeCorp\\Module\\Order\\Domain\\A',
            'App\\AcmeCorp\\Module\\Audit\\Domain\\B',
            'App\\WidgetsLtd\\Module\\Reports\\Domain\\C',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertCount(3, $tuples);
        // Sort order is by (module, tenant) per variable order (sorted alphabetically),
        // even though the array's own key order reflects pattern-capture insertion.
        self::assertSame('AcmeCorp', $tuples[0]['tenant']);
        self::assertSame('Audit', $tuples[0]['module']);
        self::assertSame('AcmeCorp', $tuples[1]['tenant']);
        self::assertSame('Order', $tuples[1]['module']);
        self::assertSame('WidgetsLtd', $tuples[2]['tenant']);
        self::assertSame('Reports', $tuples[2]['module']);
    }

    #[Test]
    public function itReturnsAnEmptyListWhenNoClassMatches(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Mdule\\{module}\\Domain\\**']),
        );

        $classes = self::classSet(['App\\Module\\Order\\Domain\\A']);

        self::assertSame([], $this->extractor->collect($template, $classes));
    }

    #[Test]
    public function itReturnsAnEmptyListForAnEmptyClassSet(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $tuples = $this->extractor->collect($template, self::classSet([]));

        self::assertSame([], $tuples);
    }

    #[Test]
    public function itAppliesNonCapturePatternsAsAnAndFilter(): void
    {
        // Combine a capture-producing pattern with a non-capture pattern.
        // The non-capture pattern restricts the set; the capture pattern
        // extracts the binding.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: [
                'App\\Module\\{module}\\Domain\\**',
                'App\\Module',  // non-capture filter — prefix on App\Module
            ]),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',     // matches both — kept
            'App\\OtherRoot\\Order\\Domain\\B',  // capture pattern would not match anyway
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([['module' => 'Order']], $tuples);
    }

    #[Test]
    public function itNarrowsTheTupleSetBySuffixUnderMatchAll(): void
    {
        // Pre-M2 (Phase 5.2 Path B), suffix acted as AND regardless of mode.
        // Post-M2, suffix is mode-aware: under `match: all` it still narrows
        // (this test), while under `match: any` it widens (see the dedicated
        // mode-any test below).
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\**'],
                suffix: ['Service'],
                mode: MatchMode::All,
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\OrderService',     // pattern + suffix
            'App\\Module\\Audit\\Domain\\AuditRepository',  // pattern only — under `all`, no tuple
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([['module' => 'Order']], $tuples);
    }

    #[Test]
    public function itDoesNotNarrowTheTupleSetBySuffixUnderMatchAny(): void
    {
        // M2 Path B positive case: under `match: any`, a class that binds via
        // the capture pattern produces a tuple even if it fails every
        // declared non-pattern criterion. The previous AND-filter behavior
        // is gone — non-pattern criteria now widen rather than narrow
        // membership, aligning expansion with runtime D2 semantics.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\**'],
                suffix: ['Service'],
                mode: MatchMode::Any,
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\OrderService',
            'App\\Module\\Audit\\Domain\\AuditRepository',  // captures `Audit` even though suffix fails
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame(
            [
                ['module' => 'Audit'],
                ['module' => 'Order'],
            ],
            $tuples,
        );
    }

    #[Test]
    public function itUnionsBindingsAcrossPatternsUnderMatchAll(): void
    {
        // Both capture-producing patterns must match the same FQN; bindings
        // union, conflicting bindings would reject the tuple.
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(
                patterns: [
                    'App\\{tenant}\\Module\\**',
                    'App\\**\\Module\\{module}\\Domain\\**',
                ],
                mode: MatchMode::All,
            ),
        );

        $classes = self::classSet([
            'App\\AcmeCorp\\Module\\Order\\Domain\\A',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertCount(1, $tuples);
        self::assertSame('AcmeCorp', $tuples[0]['tenant']);
        self::assertSame('Order', $tuples[0]['module']);
    }

    #[Test]
    public function itSkipsAClassWithConflictingBindingsUnderMatchAll(): void
    {
        $template = new TemplateLayerDefinition(
            'tag-{name}',
            new MembershipSpec(
                patterns: [
                    'App\\{name}\\Module\\Order\\**',
                    'App\\AcmeCorp\\Module\\Order\\{name}\\**',
                ],
                mode: MatchMode::All,
            ),
        );

        // The same variable {name} gets two different values; conflicting bindings.
        $classes = self::classSet([
            'App\\AcmeCorp\\Module\\Order\\InfraThing\\A',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([], $tuples);
    }

    // -------------------------------------------------------------------------
    // M1 (Phase 5.1) — exclude applied during tuple observation
    // -------------------------------------------------------------------------

    #[Test]
    public function itDropsATupleWhoseOnlyCandidateIsExcluded(): void
    {
        // Template's exclude clause uses the same capture variable {m}.
        // After binding {m}=Order from the capture pattern, exclude resolves
        // to `App\Module\Order\Domain\Generated\**`, which matches every
        // candidate class for that instance. The tuple must NOT be observed
        // — otherwise template expansion would produce a phantom `module-Order`
        // layer whose runtime membership is empty (every class falls under
        // exclude).
        $template = new TemplateLayerDefinition(
            'module-{m}',
            new MembershipSpec(
                patterns: ['App\\Module\\{m}\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Module\\{m}\\Domain\\Generated\\**']),
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\Generated\\OrderProxy',     // excluded
            'App\\Module\\Inventory\\Domain\\Stock',                  // not excluded
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        // Only Inventory survives — Order's only candidate is excluded, so
        // no tuple for `m=Order` should be observed.
        self::assertSame([['m' => 'Inventory']], $tuples);
    }

    #[Test]
    public function itKeepsATupleWhenAtLeastOneClassSurvivesExclusion(): void
    {
        // Same template/exclude as above, but the {m}=Order instance has
        // BOTH a Generated/ class (excluded) and a regular class (kept).
        // The tuple for `m=Order` must remain because at least one class
        // contributes a binding without firing exclude.
        $template = new TemplateLayerDefinition(
            'module-{m}',
            new MembershipSpec(
                patterns: ['App\\Module\\{m}\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Module\\{m}\\Domain\\Generated\\**']),
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\Generated\\OrderProxy',     // excluded
            'App\\Module\\Order\\Domain\\Order',                      // kept
            'App\\Module\\Inventory\\Domain\\Stock',                  // kept
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame(
            [
                ['m' => 'Inventory'],
                ['m' => 'Order'],
            ],
            $tuples,
        );
    }

    #[Test]
    public function itFiltersTupleObservationByExcludeSuffix(): void
    {
        // Exclude by short-name suffix (no captures involved). Same shape as
        // runtime membership.
        $template = new TemplateLayerDefinition(
            'module-{m}',
            new MembershipSpec(
                patterns: ['App\\Module\\{m}\\**'],
                exclude: new ExcludeSpec(suffix: ['Proxy']),
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\OrderProxy',          // excluded by suffix
            'App\\Module\\Audit\\AuditTrail',          // kept
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([['m' => 'Audit']], $tuples);
    }

    #[Test]
    public function itRequiresEveryDeclaredExcludeKindToMatchUnderModeAll(): void
    {
        // ExcludeSpec with `mode: all` requires every declared kind to match
        // before exclusion fires. Classes that match only one declared kind
        // survive — they contribute tuples.
        $template = new TemplateLayerDefinition(
            'module-{m}',
            new MembershipSpec(
                patterns: ['App\\Module\\{m}\\**'],
                exclude: new ExcludeSpec(
                    patterns: ['App\\Module\\{m}\\Generated\\**'],
                    suffix: ['Proxy'],
                    mode: MatchMode::All,
                ),
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Generated\\OrderProxy',           // both kinds match — excluded
            'App\\Module\\Inventory\\Generated\\InventoryService', // only pattern matches — kept
            'App\\Module\\Audit\\AuditProxy',                       // only suffix matches — kept
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame(
            [
                ['m' => 'Audit'],
                ['m' => 'Inventory'],
            ],
            $tuples,
        );
    }

    #[Test]
    public function itFiltersTupleObservationByAStaticExcludePatternWithoutCaptures(): void
    {
        // Exclude pattern with no capture variables — substitution is a no-op
        // and behaves like a plain glob filter.
        $template = new TemplateLayerDefinition(
            'module-{m}',
            new MembershipSpec(
                patterns: ['App\\Module\\{m}\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Module\\**\\Generated\\**']),
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Generated\\OrderProxy',
            'App\\Module\\Audit\\AuditTrail',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([['m' => 'Audit']], $tuples);
    }

    /**
     * @param list<string> $fqns
     */
    private static function classSet(array $fqns): ClassSet
    {
        $classes = [];
        foreach ($fqns as $fqn) {
            $position = strrpos($fqn, '\\');
            if ($position === false) {
                $classes[] = SymbolPath::forClass('', $fqn);

                continue;
            }
            $namespace = substr($fqn, 0, $position);
            $shortName = substr($fqn, $position + 1);
            $classes[] = SymbolPath::forClass($namespace, $shortName);
        }

        // Bound to an empty graph, not left unbound: an unbound factory reports
        // every class as having no parents, interfaces or attributes, and a
        // case written here that declares one of those criteria would be green
        // for that reason alone.
        $factory = new ClassContextFactory();
        $factory->bindGraph(AdjacencyGraphBuilder::empty());

        return new ClassSet($classes, $factory);
    }
}
