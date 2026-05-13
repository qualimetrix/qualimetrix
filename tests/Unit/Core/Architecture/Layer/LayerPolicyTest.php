<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Layer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;

#[CoversClass(LayerPolicy::class)]
final class LayerPolicyTest extends TestCase
{
    #[Test]
    public function isAllowed_sameLayerAlwaysAllowedEvenWhenNotInMap(): void
    {
        $policy = new LayerPolicy([]);

        self::assertTrue($policy->isAllowed('controller', 'controller'));
    }

    #[Test]
    public function isAllowed_sameLayerAllowedWhenInMap(): void
    {
        $policy = new LayerPolicy([
            'controller' => ['service'],
        ]);

        self::assertTrue($policy->isAllowed('controller', 'controller'));
    }

    #[Test]
    public function isAllowed_targetInAllowList_returnsTrue(): void
    {
        $policy = new LayerPolicy([
            'controller' => ['service', 'domain'],
        ]);

        self::assertTrue($policy->isAllowed('controller', 'service'));
        self::assertTrue($policy->isAllowed('controller', 'domain'));
    }

    #[Test]
    public function isAllowed_targetNotInAllowList_returnsFalse(): void
    {
        $policy = new LayerPolicy([
            'controller' => ['service'],
        ]);

        self::assertFalse($policy->isAllowed('controller', 'repository'));
    }

    #[Test]
    public function isAllowed_unknownSourceLayer_returnsFalse(): void
    {
        $policy = new LayerPolicy([
            'controller' => ['service'],
        ]);

        self::assertFalse($policy->isAllowed('unknown', 'service'));
    }

    #[Test]
    public function isAllowed_emptyAllowListForKnownSource_returnsFalseForDifferentLayer(): void
    {
        $policy = new LayerPolicy([
            'core' => [],
        ]);

        self::assertFalse($policy->isAllowed('core', 'service'));
        // But same-layer remains allowed.
        self::assertTrue($policy->isAllowed('core', 'core'));
    }

    #[Test]
    public function allowedTargets_returnsConfiguredList(): void
    {
        $policy = new LayerPolicy([
            'controller' => ['service', 'domain'],
        ]);

        self::assertSame(['service', 'domain'], $policy->allowedTargets('controller'));
    }

    #[Test]
    public function allowedTargets_returnsEmptyListForUnknownLayer(): void
    {
        $policy = new LayerPolicy([
            'controller' => ['service'],
        ]);

        self::assertSame([], $policy->allowedTargets('unknown'));
    }

    #[Test]
    public function allowedTargets_returnsEmptyListForExplicitlyEmptyAllowList(): void
    {
        $policy = new LayerPolicy([
            'core' => [],
        ]);

        self::assertSame([], $policy->allowedTargets('core'));
    }

    #[Test]
    public function knownLayers_returnsSortedUnionOfKeysAndTargets(): void
    {
        $policy = new LayerPolicy([
            'controller' => ['service'],
            'service' => ['repository'],
            'analysis' => ['service', 'reporting'],
        ]);

        self::assertSame(
            ['analysis', 'controller', 'reporting', 'repository', 'service'],
            $policy->knownLayers(),
        );
    }

    #[Test]
    public function knownLayers_deduplicatesAcrossKeysAndValues(): void
    {
        $policy = new LayerPolicy([
            'a' => ['b', 'c'],
            'b' => ['a', 'c'],
        ]);

        self::assertSame(['a', 'b', 'c'], $policy->knownLayers());
    }

    #[Test]
    public function knownLayers_emptyPolicyReturnsEmpty(): void
    {
        self::assertSame([], (new LayerPolicy([]))->knownLayers());
    }

    #[Test]
    public function knownLayers_keysOnlyWithEmptyAllowLists(): void
    {
        $policy = new LayerPolicy([
            'core' => [],
            'service' => [],
        ]);

        self::assertSame(['core', 'service'], $policy->knownLayers());
    }

    #[Test]
    public function isAllowed_unknownSourceLayerReturnsFalseRegardlessOfTarget(): void
    {
        // Documented contract: callers MUST pre-resolve $from via LayerRegistry.
        // An unknown source layer is intentionally treated as "no targets allowed",
        // not as a defensive fallback. This test pins the strict behaviour.
        $policy = new LayerPolicy([
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
        // Same-layer short-circuit precedes the allow-map lookup. An unknown
        // source name still resolves "self → self" as true. This is fine: in
        // practice, a non-empty $from === $to could only originate from a
        // resolved layer, but the contract is deliberately permissive on
        // identity to avoid spurious "self-cycle" violations.
        $policy = new LayerPolicy([
            'controller' => ['service'],
        ]);

        self::assertTrue($policy->isAllowed('unknown', 'unknown'));
    }
}
