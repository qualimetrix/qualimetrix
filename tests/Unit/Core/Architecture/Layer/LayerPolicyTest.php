<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Layer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Allow\AllowListEntry;
use Qualimetrix\Core\Architecture\Allow\AllowTarget;
use Qualimetrix\Core\Architecture\Allow\LayerSelector;
use Qualimetrix\Core\Architecture\Allow\LayerSelectorParser;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Tests\Support\Architecture\AllowListBuilder;

#[CoversClass(LayerPolicy::class)]
final class LayerPolicyTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Phase-1 BC: exact-only allow-lists behave identically to the pre-Step-C map shape
    // -------------------------------------------------------------------------

    #[Test]
    public function isAllowed_sameLayerAlwaysAllowedEvenWhenNotInEntryList(): void
    {
        $policy = new LayerPolicy([]);

        self::assertTrue($policy->isAllowed('controller', 'controller'));
    }

    #[Test]
    public function isAllowed_sameLayerAllowedWhenEntryExists(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service'],
        ]);

        self::assertTrue($policy->isAllowed('controller', 'controller'));
    }

    #[Test]
    public function isAllowed_exactTargetInAllowList_returnsTrue(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service', 'domain'],
        ]);

        self::assertTrue($policy->isAllowed('controller', 'service'));
        self::assertTrue($policy->isAllowed('controller', 'domain'));
    }

    #[Test]
    public function isAllowed_targetNotInAllowList_returnsFalse(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service'],
        ]);

        self::assertFalse($policy->isAllowed('controller', 'repository'));
    }

    #[Test]
    public function isAllowed_unknownSourceLayer_returnsFalse(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service'],
        ]);

        self::assertFalse($policy->isAllowed('unknown', 'service'));
    }

    #[Test]
    public function isAllowed_emptyTargetListForKnownSource_returnsFalseForDifferentLayer(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'core' => [],
        ]);

        self::assertFalse($policy->isAllowed('core', 'service'));
        // But same-layer remains allowed.
        self::assertTrue($policy->isAllowed('core', 'core'));
    }

    #[Test]
    public function allowedTargets_returnsConfiguredExactNames(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service', 'domain'],
        ]);

        self::assertSame(['service', 'domain'], $policy->allowedTargets('controller'));
    }

    #[Test]
    public function allowedTargets_returnsEmptyListForUnknownSource(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service'],
        ]);

        self::assertSame([], $policy->allowedTargets('unknown'));
    }

    #[Test]
    public function allowedTargets_returnsEmptyListForExplicitlyEmptyAllowList(): void
    {
        $policy = AllowListBuilder::policyFromExactMap([
            'core' => [],
        ]);

        self::assertSame([], $policy->allowedTargets('core'));
    }

    // -------------------------------------------------------------------------
    // Step C: glob selectors on source and target sides
    // -------------------------------------------------------------------------

    #[Test]
    public function isAllowed_globSourceMatchesAnyName(): void
    {
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::glob('domain-*'),
                [new AllowTarget(LayerSelector::exact('shared'))],
            ),
        ]);

        self::assertTrue($policy->isAllowed('domain-Order', 'shared'));
        self::assertTrue($policy->isAllowed('domain-Inventory', 'shared'));
        self::assertFalse($policy->isAllowed('controller', 'shared'));
    }

    #[Test]
    public function isAllowed_globTargetMatchesAnyName(): void
    {
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('controller'),
                [new AllowTarget(LayerSelector::glob('*-repository'))],
            ),
        ]);

        self::assertTrue($policy->isAllowed('controller', 'user-repository'));
        self::assertTrue($policy->isAllowed('controller', 'order-repository'));
        self::assertFalse($policy->isAllowed('controller', 'random'));
    }

    #[Test]
    public function isAllowed_capturedSelectorEnforcesSameBindingIdentity(): void
    {
        // Step E binding-aware semantics: 'app-{m}' → 'domain-{m}' allows
        // app-Order to depend on domain-Order (same binding) but not on
        // domain-Inventory (binding mismatch). The captured target's
        // {@code {m}} segment is substituted with the source-side binding
        // value before matching.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelectorParser::parse('app-{m}'),
                [new AllowTarget(LayerSelectorParser::parse('domain-{m}'))],
            ),
        ]);

        self::assertFalse($policy->isAllowed('app-Order', 'unrelated'));
        self::assertTrue($policy->isAllowed('app-Order', 'domain-Order'));
        self::assertFalse($policy->isAllowed('app-Order', 'domain-Inventory'));
    }

    #[Test]
    public function isAllowed_allowCrossInstanceSwapsSourceBindingForEmptyBinding(): void
    {
        // With allow_cross_instance: true, the policy passes an empty binding
        // into the captured target's matchesTarget call. The captured target
        // degrades to per-segment default patterns and accepts any same-shape
        // name, including cross-instance edges.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelectorParser::parse('app-{m}'),
                [
                    new AllowTarget(
                        LayerSelectorParser::parse('domain-{m}'),
                        allowCrossInstance: true,
                    ),
                ],
            ),
        ]);

        self::assertTrue($policy->isAllowed('app-Order', 'domain-Order'));
        self::assertTrue($policy->isAllowed('app-Order', 'domain-Inventory'));
        self::assertFalse($policy->isAllowed('app-Order', 'controller'));
    }

    #[Test]
    public function isAllowed_capturedSourceWithExactTargetIgnoresBinding(): void
    {
        // Captured source binding flows through but the exact target's
        // matchesTarget ignores binding entirely — every instance of the
        // captured source layer can reach the same exact target layer.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelectorParser::parse('domain-{m}'),
                [new AllowTarget(LayerSelector::exact('vendor'))],
            ),
        ]);

        self::assertTrue($policy->isAllowed('domain-Order', 'vendor'));
        self::assertTrue($policy->isAllowed('domain-Inventory', 'vendor'));
        self::assertFalse($policy->isAllowed('domain-Order', 'other'));
    }

    #[Test]
    public function allowedTargets_returnsAllSelectorKindsAsOriginalStrings(): void
    {
        // Exact, glob, and captured targets all surface as their original
        // selector strings — the recommendation builder renders them verbatim,
        // which is accurate for all three kinds because the original string
        // is the shape the user copies back into the YAML config.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('controller'),
                [
                    new AllowTarget(LayerSelector::exact('service')),
                    new AllowTarget(LayerSelector::glob('*-repository')),
                    new AllowTarget(LayerSelectorParser::parse('app-{m}')),
                ],
            ),
        ]);

        self::assertSame(
            ['service', '*-repository', 'app-{m}'],
            $policy->allowedTargets('controller'),
        );
    }

    #[Test]
    public function allowedTargets_dedupesAcrossMatchingEntriesByOriginalString(): void
    {
        // Two entries match the same source name; duplicate target descriptors
        // are emitted only once.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('controller'),
                [new AllowTarget(LayerSelector::glob('*-repository'))],
            ),
            new AllowListEntry(
                LayerSelector::glob('controll*'),
                [new AllowTarget(LayerSelector::glob('*-repository'))],
            ),
        ]);

        self::assertSame(['*-repository'], $policy->allowedTargets('controller'));
    }

    // -------------------------------------------------------------------------
    // Contract pins carried over from pre-Step-C
    // -------------------------------------------------------------------------

    #[Test]
    public function isAllowed_unknownSourceLayerReturnsFalseRegardlessOfTarget(): void
    {
        // Documented contract: callers MUST pre-resolve $from via LayerRegistry.
        // An unknown source layer is intentionally treated as "no targets allowed",
        // not as a defensive fallback. This test pins the strict behaviour.
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service'],
            'service' => ['repository'],
        ]);

        self::assertFalse($policy->isAllowed('unknown', 'service'));
        self::assertFalse($policy->isAllowed('unknown', 'controller'));
        self::assertFalse($policy->isAllowed('unknown', 'repository'));
        self::assertFalse($policy->isAllowed('', 'service'));
    }

    #[Test]
    public function isAllowed_unknownSourceLayerStillAllowsSameLayer(): void
    {
        // Same-layer short-circuit precedes the entry walk. An unknown source
        // name still resolves "self → self" as true. This is fine: in practice,
        // a non-empty $from === $to could only originate from a resolved layer,
        // but the contract is deliberately permissive on identity to avoid
        // spurious "self-cycle" violations.
        $policy = AllowListBuilder::policyFromExactMap([
            'controller' => ['service'],
        ]);

        self::assertTrue($policy->isAllowed('unknown', 'unknown'));
    }

    // -------------------------------------------------------------------------
    // Step G: relations filter (edge-type-aware allow-list)
    // -------------------------------------------------------------------------

    #[Test]
    public function isAllowed_relationsFilter_acceptsListedDependencyType(): void
    {
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [new AllowTarget(
                    LayerSelector::exact('contracts'),
                    relations: [DependencyType::Extends, DependencyType::Implements],
                )],
            ),
        ]);

        self::assertTrue($policy->isAllowed('domain', 'contracts', DependencyType::Extends));
        self::assertTrue($policy->isAllowed('domain', 'contracts', DependencyType::Implements));
    }

    #[Test]
    public function isAllowed_relationsFilter_rejectsUnlistedDependencyType(): void
    {
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [new AllowTarget(
                    LayerSelector::exact('contracts'),
                    relations: [DependencyType::Extends, DependencyType::Implements],
                )],
            ),
        ]);

        self::assertFalse($policy->isAllowed('domain', 'contracts', DependencyType::StaticCall));
        self::assertFalse($policy->isAllowed('domain', 'contracts', DependencyType::New_));
    }

    #[Test]
    public function isAllowed_relationsNull_acceptsAnyDependencyType(): void
    {
        // The legacy semantics (and the bare-string short-form) leave
        // relations=null on the AllowTarget — any DependencyType is accepted.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [new AllowTarget(LayerSelector::exact('contracts'))],
            ),
        ]);

        foreach (DependencyType::cases() as $type) {
            self::assertTrue(
                $policy->isAllowed('domain', 'contracts', $type),
                "bare-string target must accept {$type->value}",
            );
        }
    }

    #[Test]
    public function isAllowed_typeNullBypassesRelationsFilter(): void
    {
        // Callers that don't care about edge granularity (legacy tests, the
        // reachability surface) can omit the type argument; the relations gate
        // is then bypassed and the result depends only on selector matching.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [new AllowTarget(
                    LayerSelector::exact('contracts'),
                    relations: [DependencyType::Extends],
                )],
            ),
        ]);

        self::assertTrue($policy->isAllowed('domain', 'contracts'));
        self::assertTrue($policy->isAllowed('domain', 'contracts', null));
    }

    #[Test]
    public function isAllowed_sameLayerAlwaysAllowedRegardlessOfRelations(): void
    {
        // Same-layer short-circuit precedes the entry walk and the relations
        // gate. A class extending another class in the same layer is always
        // permitted regardless of whether any allow entry mentions extends.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [new AllowTarget(
                    LayerSelector::exact('contracts'),
                    relations: [DependencyType::StaticCall],
                )],
            ),
        ]);

        self::assertTrue($policy->isAllowed('domain', 'domain', DependencyType::Extends));
        self::assertTrue($policy->isAllowed('domain', 'domain', DependencyType::Catch_));
    }

    #[Test]
    public function isAllowed_overlappingAllowEntries_shortFormDominates(): void
    {
        // UNION semantics: when several targets within one source resolve to
        // the same target layer, the broader (bare-string, relations=null)
        // target dominates. Declaration order doesn't matter for the boolean
        // accept result — every relation kind is allowed as long as one
        // matching target has relations=null.
        $bareFirst = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [
                    new AllowTarget(LayerSelector::exact('contracts')),
                    new AllowTarget(
                        LayerSelector::exact('contracts'),
                        relations: [DependencyType::Extends],
                    ),
                ],
            ),
        ]);

        $longFormFirst = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [
                    new AllowTarget(
                        LayerSelector::exact('contracts'),
                        relations: [DependencyType::Extends],
                    ),
                    new AllowTarget(LayerSelector::exact('contracts')),
                ],
            ),
        ]);

        // Edge type not listed by the long-form target — bare-string sibling
        // must rescue it in BOTH declaration orders.
        self::assertTrue($bareFirst->isAllowed('domain', 'contracts', DependencyType::StaticCall));
        self::assertTrue($longFormFirst->isAllowed('domain', 'contracts', DependencyType::StaticCall));
    }

    #[Test]
    public function isAllowed_overlappingAllowEntries_relationsUnion(): void
    {
        // Without a bare-string rescue, multiple long-form targets covering the
        // same target layer combine into the union of their relations lists.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [
                    new AllowTarget(
                        LayerSelector::exact('contracts'),
                        relations: [DependencyType::Extends],
                    ),
                    new AllowTarget(
                        LayerSelector::exact('contracts'),
                        relations: [DependencyType::Implements],
                    ),
                ],
            ),
        ]);

        self::assertTrue($policy->isAllowed('domain', 'contracts', DependencyType::Extends));
        self::assertTrue($policy->isAllowed('domain', 'contracts', DependencyType::Implements));
        self::assertFalse($policy->isAllowed('domain', 'contracts', DependencyType::StaticCall));
    }

    #[Test]
    public function allowedTargets_surfaceRelationsTrailer_whenTargetHasRelations(): void
    {
        // M1 fix: the recommendation surface must tell the user that a
        // long-form target only accepts certain edge kinds — otherwise the
        // rendered "Allowed targets for layer X: vendor" message misleads.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [
                    new AllowTarget(LayerSelector::exact('contracts')),
                    new AllowTarget(
                        LayerSelector::exact('vendor'),
                        relations: [DependencyType::Extends, DependencyType::Implements],
                    ),
                ],
            ),
        ]);

        self::assertSame(
            ['contracts', 'vendor (relations: extends, implements)'],
            $policy->allowedTargets('domain'),
        );
    }

    #[Test]
    public function allowedTargets_dedupesBareAndLongFormDescriptorsSeparately(): void
    {
        // A bare 'vendor' and a 'vendor' with relations are semantically
        // distinct (UNION semantics — see overlapping siblings test). The
        // recommendation surface must reflect both: the user can either
        // route any edge through the bare target or rely on the restricted
        // sibling for the relation kinds it covers.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [
                    new AllowTarget(LayerSelector::exact('vendor')),
                    new AllowTarget(
                        LayerSelector::exact('vendor'),
                        relations: [DependencyType::Extends],
                    ),
                ],
            ),
        ]);

        self::assertSame(
            ['vendor', 'vendor (relations: extends)'],
            $policy->allowedTargets('domain'),
        );
    }

    #[Test]
    public function isAllowed_allowCrossInstanceCombinedWithRelations_appliesBothGates(): void
    {
        // M2 fix: end-to-end pin that allow_cross_instance and relations
        // combine cleanly — the binding gate is lifted (any module instance
        // of the captured target is accepted) AND the relation gate still
        // restricts the edge kind.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelectorParser::parse('app-{m}'),
                [
                    new AllowTarget(
                        LayerSelectorParser::parse('domain-{m}'),
                        relations: [DependencyType::Extends],
                        allowCrossInstance: true,
                    ),
                ],
            ),
        ]);

        // Cross-module Extends: both gates pass.
        self::assertTrue($policy->isAllowed('app-Order', 'domain-Inventory', DependencyType::Extends));
        // Same-module StaticCall: relations rejects even when binding identity holds.
        self::assertFalse($policy->isAllowed('app-Order', 'domain-Order', DependencyType::StaticCall));
    }

    #[Test]
    public function isAllowed_relationsFilter_appliesAcrossEntriesNotJustTargets(): void
    {
        // The UNION property must hold across separate AllowListEntry rows
        // too (not only across targets within one entry). This pins that the
        // walk doesn't short-circuit on the first matching source-entry when
        // the first entry's relations don't accept the edge.
        $policy = new LayerPolicy([
            new AllowListEntry(
                LayerSelector::exact('domain'),
                [new AllowTarget(
                    LayerSelector::exact('contracts'),
                    relations: [DependencyType::Extends],
                )],
            ),
            new AllowListEntry(
                LayerSelector::glob('*main'),
                [new AllowTarget(
                    LayerSelector::exact('contracts'),
                    relations: [DependencyType::StaticCall],
                )],
            ),
        ]);

        self::assertTrue($policy->isAllowed('domain', 'contracts', DependencyType::Extends));
        self::assertTrue($policy->isAllowed('domain', 'contracts', DependencyType::StaticCall));
        self::assertFalse($policy->isAllowed('domain', 'contracts', DependencyType::Implements));
    }
}
