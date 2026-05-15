<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Processing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\ClassContextFactory;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\ExcludeSpec;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Architecture\Processing\TupleExtractor;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Pins the behavior of {@see TupleExtractor} extracted from
 * {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage} during
 * Phase 4.1 of the remediation (ADR 0008). The {@code LayerExpansionStageTest}
 * still covers the end-to-end orchestration; this test focuses on the helper
 * surface in isolation.
 */
#[CoversClass(TupleExtractor::class)]
final class TupleExtractorTest extends TestCase
{
    private TupleExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TupleExtractor();
    }

    #[Test]
    public function collect_singleVariableTemplate_dedupesAndLexSorts(): void
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
    public function collect_multiVariable_observedTuplesNotCartesian(): void
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
    public function collect_noClassMatches_returnsEmptyList(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Mdule\\{module}\\Domain\\**']),
        );

        $classes = self::classSet(['App\\Module\\Order\\Domain\\A']);

        self::assertSame([], $this->extractor->collect($template, $classes));
    }

    #[Test]
    public function collect_emptyClassSet_returnsEmptyList(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $tuples = $this->extractor->collect($template, self::classSet([]));

        self::assertSame([], $tuples);
    }

    #[Test]
    public function collect_appliesNonCapturePatternsAsAndFilter(): void
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
    public function collect_appliesSuffixCriterionAsAndFilter(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\**'],
                suffix: ['Service'],
            ),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\OrderService',
            'App\\Module\\Order\\Domain\\OrderRepository',
        ]);

        $tuples = $this->extractor->collect($template, $classes);

        self::assertSame([['module' => 'Order']], $tuples);
    }

    #[Test]
    public function collect_matchAll_unionsBindingsAcrossPatterns(): void
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
    public function collect_matchAll_conflictingBindings_skipsClass(): void
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
    public function collect_excludePatternFiresPerInstance_dropsTuple(): void
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
    public function collect_excludePatternPartialMatch_keepsTupleWhenAtLeastOneClassSurvives(): void
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
    public function collect_excludeSuffix_filtersTupleObservation(): void
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
    public function collect_excludeModeAll_requiresEveryDeclaredKindToMatch(): void
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
    public function collect_staticExcludePatternWithoutCaptures_filtersTupleObservation(): void
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

        return new ClassSet($classes, new ClassContextFactory());
    }
}
