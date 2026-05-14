<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Architecture;

use Qualimetrix\Core\Architecture\Allow\AllowListEntry;
use Qualimetrix\Core\Architecture\Allow\AllowTarget;
use Qualimetrix\Core\Architecture\Allow\LayerSelector;
use Qualimetrix\Core\Architecture\Layer\LayerPolicy;

/**
 * Test helper: builds {@see LayerPolicy} from the legacy
 * {@code array<string, list<string>>} shape using exact selectors.
 *
 * Pre-Phase-2 Step C the policy ctor accepted that shape directly; consumer
 * tests still want the terse map syntax to keep their intent readable. This
 * helper exists exclusively for tests — production code constructs entries
 * directly via {@see LayerSelectorParser::parse()}.
 */
final class AllowListBuilder
{
    /**
     * Builds a {@see LayerPolicy} from a {@code source → list<target>} map.
     * Every key and target is treated as an exact selector; callers that need
     * glob / captured selectors should build the entry list explicitly.
     *
     * @param array<string, list<string>> $map
     */
    public static function policyFromExactMap(array $map): LayerPolicy
    {
        return new LayerPolicy(self::entriesFromExactMap($map));
    }

    /**
     * @param array<string, list<string>> $map
     *
     * @return list<AllowListEntry>
     */
    public static function entriesFromExactMap(array $map): array
    {
        $entries = [];
        foreach ($map as $source => $targets) {
            $allowTargets = [];
            foreach ($targets as $target) {
                $allowTargets[] = new AllowTarget(LayerSelector::exact($target));
            }
            $entries[] = new AllowListEntry(LayerSelector::exact($source), $allowTargets);
        }

        return $entries;
    }
}
