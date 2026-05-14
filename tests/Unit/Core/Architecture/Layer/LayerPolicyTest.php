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
}
