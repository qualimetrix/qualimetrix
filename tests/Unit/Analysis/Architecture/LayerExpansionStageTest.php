<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Architecture\LayerExpansionException;
use Qualimetrix\Analysis\Architecture\LayerExpansionResult;
use Qualimetrix\Analysis\Architecture\LayerExpansionStage;
use Qualimetrix\Core\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Core\Architecture\Layer\ClassSet;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\MatchMode;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;
use Qualimetrix\Core\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(LayerExpansionStage::class)]
#[CoversClass(LayerExpansionResult::class)]
#[CoversClass(LayerExpansionException::class)]
final class LayerExpansionStageTest extends TestCase
{
    private LayerExpansionStage $stage;

    protected function setUp(): void
    {
        $this->stage = new LayerExpansionStage();
    }

    // -------------------------------------------------------------------------
    // Basic expansion: single-variable template + non-template entries
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_singleVariableTemplate_producesOneLayerPerObservedModule(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\Customer',
            'App\\Module\\Order\\Domain\\Order',
            'App\\Module\\Audit\\Domain\\AuditTrail',
            'App\\Service\\Other',
        ]);

        $result = $this->stage->expand([$template], $classes, 500);

        self::assertSame([], $result->emptyTemplateNames);
        self::assertCount(2, $result->expandedLayers);
        self::assertSame('domain-Audit', $result->expandedLayers[0]->name(), 'lex-sorted first');
        self::assertSame('domain-Order', $result->expandedLayers[1]->name());

        // The expanded layer for Order should carry the substituted pattern.
        self::assertSame(
            ['App\\Module\\Order\\Domain\\**'],
            $result->expandedLayers[1]->membership()->patterns,
        );
    }

    #[Test]
    public function expand_singleVariableTemplate_deduplicatesObservedTuples(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',
            'App\\Module\\Order\\Domain\\B',
            'App\\Module\\Order\\Domain\\C',
        ]);

        $result = $this->stage->expand([$template], $classes, 500);

        self::assertCount(1, $result->expandedLayers);
        self::assertSame('domain-Order', $result->expandedLayers[0]->name());
    }

    // -------------------------------------------------------------------------
    // Multi-variable templates expand by observed tuples (not cartesian)
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_multiVariableTemplate_doesNotProduceCartesianProduct(): void
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

        $result = $this->stage->expand([$template], $classes, 500);

        $names = array_map(static fn(LayerDefinition $l): string => $l->name(), $result->expandedLayers);

        // 3 observed tuples — NOT 6 (2 tenants × 3 modules cartesian would be 6).
        // Sorted lex by (module, tenant) — variables sorted alphabetically.
        self::assertSame(
            [
                'cluster-AcmeCorp-Audit',
                'cluster-AcmeCorp-Order',
                'cluster-WidgetsLtd-Reports',
            ],
            $names,
        );
    }

    // -------------------------------------------------------------------------
    // Static layers + templates interleaved
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_interleavesStaticAndTemplateEntriesInDeclarationOrder(): void
    {
        $staticBefore = new LayerDefinition('infra', new MembershipSpec(patterns: ['App\\Infra\\**']));
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );
        $staticAfter = new LayerDefinition('shared', new MembershipSpec(patterns: ['App\\Shared\\**']));

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',
            'App\\Module\\Audit\\Domain\\B',
        ]);

        $result = $this->stage->expand([$staticBefore, $template, $staticAfter], $classes, 500);

        $names = array_map(static fn(LayerDefinition $l): string => $l->name(), $result->expandedLayers);
        self::assertSame(['infra', 'domain-Audit', 'domain-Order', 'shared'], $names);
    }

    // -------------------------------------------------------------------------
    // Empty-template signal
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_emptyTemplateMatchesNoClass_addsToEmptyList(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Mdule\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\Customer',
        ]);

        $result = $this->stage->expand([$template], $classes, 500);

        self::assertSame([], $result->expandedLayers);
        self::assertSame(['domain-{module}'], $result->emptyTemplateNames);
    }

    // -------------------------------------------------------------------------
    // Ceiling
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_cartesianBlowupCeiling_failsWithActionableMessage(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',
            'App\\Module\\Audit\\Domain\\B',
            'App\\Module\\Reports\\Domain\\C',
        ]);

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('architecture.max_expanded_layers ceiling of 2');

        $this->stage->expand([$template], $classes, 2);
    }

    #[Test]
    public function expand_cumulativeCeilingAcrossMultipleTemplates_reportsBothCounts(): void
    {
        // First template produces 2 layers; second produces 2 more. Cumulative
        // 4 > ceiling 3 → fail. Message must name the second template's
        // own contribution AND the cumulative count.
        $first = new TemplateLayerDefinition(
            'first-{module}',
            new MembershipSpec(patterns: ['App\\First\\{module}\\**']),
        );
        $second = new TemplateLayerDefinition(
            'second-{module}',
            new MembershipSpec(patterns: ['App\\Second\\{module}\\**']),
        );

        $classes = self::classSet([
            'App\\First\\Order\\Foo',
            'App\\First\\Audit\\Bar',
            'App\\Second\\Reports\\Baz',
            'App\\Second\\Billing\\Qux',
        ]);

        try {
            $this->stage->expand([$first, $second], $classes, 3);
            self::fail('Expected LayerExpansionException due to cumulative ceiling overflow.');
        } catch (LayerExpansionException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('"second-{module}"', $message);
            self::assertStringContainsString('added 2 layers', $message);
            self::assertStringContainsString('cumulative 4', $message);
            self::assertStringContainsString('ceiling of 3', $message);
        }
    }

    #[Test]
    public function expand_invalidMaxExpansion_rejected(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('max-expansion ceiling must be >= 1');

        $this->stage->expand([$template], self::classSet([]), 0);
    }

    // -------------------------------------------------------------------------
    // Collisions
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_staticAndTemplateProduceSameName_rejected(): void
    {
        $static = new LayerDefinition(
            'domain-order',
            new MembershipSpec(patterns: ['App\\OrdersLegacy\\**']),
        );
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\order\\Domain\\A',
        ]);

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('"domain-order"');
        $this->expectExceptionMessage('static layer');

        $this->stage->expand([$static, $template], $classes, 500);
    }

    #[Test]
    public function expand_twoTemplatesProduceSameName_rejected(): void
    {
        $first = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );
        $second = new TemplateLayerDefinition(
            'domain-{name}',
            new MembershipSpec(patterns: ['App\\OtherModules\\{name}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\A',
            'App\\OtherModules\\Order\\Domain\\B',
        ]);

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('"domain-Order"');

        $this->stage->expand([$first, $second], $classes, 500);
    }

    // -------------------------------------------------------------------------
    // Invalid concrete names produced by substitution
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_substitutedNameStartingWithNonLetter_producesActionableError(): void
    {
        // Variable is the leading segment of the name template; a binding
        // starting with a digit produces a concrete name that fails the
        // relaxed regex (must start with a letter).
        $template = new TemplateLayerDefinition(
            '{module}-domain',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );

        $classes = self::classSet([
            'App\\Module\\1Numeric\\Domain\\X',
        ]);

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('invalid concrete layer name');
        $this->expectExceptionMessage('Binding values must consist of');

        $this->stage->expand([$template], $classes, 500);
    }

    // -------------------------------------------------------------------------
    // D7 carve-out — non-capturing filters always AND
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_d7CarveOut_nonCapturingSuffixFiltersOutClassesEvenUnderMatchAny(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                suffix: ['Aggregate'],
                mode: MatchMode::Any,
            ),
        );

        // Only the class ending in `Aggregate` should contribute a tuple.
        $classes = self::classSet([
            'App\\Module\\Order\\Domain\\OrderAggregate', // matches both
            'App\\Module\\Audit\\Domain\\AuditService',   // pattern only — suffix fails
        ]);

        $result = $this->stage->expand([$template], $classes, 500);

        $names = array_map(static fn(LayerDefinition $l): string => $l->name(), $result->expandedLayers);
        self::assertSame(['domain-Order'], $names);
    }

    #[Test]
    public function expand_d7CarveOut_nonCapturePatternIsAndFilter(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: [
                'App\\Domain\\**',                          // non-capture filter (AND)
                'App\\Domain\\{module}\\Entity\\**',        // capture-producing
            ]),
        );

        $classes = self::classSet([
            'App\\Domain\\Order\\Entity\\Customer',  // both match
            'App\\Domain\\Other\\Service\\X',         // only filter matches, no capture match
            'App\\Outside\\Order\\Entity\\Y',         // filter fails
        ]);

        $result = $this->stage->expand([$template], $classes, 500);

        $names = array_map(static fn(LayerDefinition $l): string => $l->name(), $result->expandedLayers);
        self::assertSame(['domain-Order'], $names);
    }

    #[Test]
    public function expand_d7CarveOut_nonGlobNonCaptureFilterUsesPhase1PrefixSemantics(): void
    {
        // Filter pattern 'App\Domain' (no glob, no capture) must behave like
        // a Phase-1 namespace prefix — match the namespace itself AND any
        // class beneath it. The original Step D implementation routed this
        // through CapturePattern, which exact-matches non-glob residues and
        // silently rejected matching classes (causing empty-template false
        // positives). Regression: keep this pinned.
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: [
                'App\\Domain',                              // non-glob non-capture filter — PREFIX semantics
                'App\\Domain\\{module}\\Entity\\**',
            ]),
        );

        $classes = self::classSet([
            'App\\Domain\\Order\\Entity\\Customer',
            'App\\Domain\\Audit\\Entity\\Trail',
            'App\\Other\\Order\\Entity\\X',
        ]);

        $result = $this->stage->expand([$template], $classes, 500);

        $names = array_map(static fn(LayerDefinition $l): string => $l->name(), $result->expandedLayers);
        self::assertSame(['domain-Audit', 'domain-Order'], $names);
    }

    // -------------------------------------------------------------------------
    // Partial bindings under match: any with multi-variable templates
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_matchAnyWithDisjointBindingPatterns_failsWithActionableMessage(): void
    {
        // Template references both {tenant} and {module}. Each pattern binds
        // only one of them. Under match: any the first matching pattern wins,
        // leaving the other variable unbound — explicit error expected.
        $template = new TemplateLayerDefinition(
            'cluster-{tenant}-{module}',
            new MembershipSpec(
                patterns: [
                    'App\\Tenant\\{tenant}\\**',  // binds tenant, not module
                    'App\\Mod\\{module}\\**',     // binds module, not tenant
                ],
                mode: MatchMode::Any,
            ),
        );

        $classes = self::classSet([
            'App\\Tenant\\AcmeCorp\\Service',
        ]);

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('incomplete binding tuple');
        $this->expectExceptionMessage('module');
        $this->expectExceptionMessage('match: all');

        $this->stage->expand([$template], $classes, 500);
    }

    // -------------------------------------------------------------------------
    // Match modes (Any vs All) for capture-producing patterns
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_matchAll_requiresAllCaptureProducingPatternsToAgree(): void
    {
        $template = new TemplateLayerDefinition(
            'mod-{module}',
            new MembershipSpec(
                patterns: [
                    'App\\Mirror\\{module}\\**',
                    'App\\Origin\\{module}\\**',
                ],
                mode: MatchMode::All,
            ),
        );

        $classes = self::classSet([
            // Class only matches one of the two patterns — under `all` it's excluded.
            'App\\Mirror\\Order\\Foo',
            // Pseudo-double-match impossible for single FQN, so `all` with two
            // capture-producing patterns naturally yields zero tuples — empty template.
        ]);

        $result = $this->stage->expand([$template], $classes, 500);

        self::assertSame([], $result->expandedLayers);
        self::assertSame(['mod-{module}'], $result->emptyTemplateNames);
    }

    // -------------------------------------------------------------------------
    // Static-only entries (no templates) — passthrough
    // -------------------------------------------------------------------------

    #[Test]
    public function expand_noTemplates_returnsStaticLayersUnchanged(): void
    {
        $layers = [
            new LayerDefinition('infra', new MembershipSpec(patterns: ['App\\Infra\\**'])),
            new LayerDefinition('domain', new MembershipSpec(patterns: ['App\\Domain\\**'])),
        ];

        $result = $this->stage->expand($layers, self::classSet([]), 500);

        self::assertSame($layers, $result->expandedLayers);
        self::assertSame([], $result->emptyTemplateNames);
    }

    #[Test]
    public function expand_duplicateStaticName_rejected(): void
    {
        $first = new LayerDefinition('infra', new MembershipSpec(patterns: ['App\\Infra\\**']));
        $second = new LayerDefinition('infra', new MembershipSpec(patterns: ['App\\Other\\**']));

        $this->expectException(LayerExpansionException::class);
        $this->expectExceptionMessage('"infra"');

        $this->stage->expand([$first, $second], self::classSet([]), 500);
    }

    // -------------------------------------------------------------------------
    // LayerExpansionResult helper
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyResult_factoryProducesEmptyState(): void
    {
        $result = LayerExpansionResult::empty();

        self::assertSame([], $result->expandedLayers());
        self::assertSame([], $result->emptyTemplateNames());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
