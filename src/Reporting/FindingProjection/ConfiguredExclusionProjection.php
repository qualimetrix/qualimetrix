<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\NamespaceExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Filter\PathExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Filter\PredicateFilterStage;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\PathMatcher;

/** Projects configured exclusions into their ordered finding filters. */
final class ConfiguredExclusionProjection
{
    private function __construct() {}

    /** @return list<PredicateFilterStage> */
    public static function stages(FindingProjectionOptions $options): array
    {
        $fileScope = DeclaredChannelFileScope::create();
        $stages = [];
        if ($options->suppressPaths !== []) {
            $stages[] = new PredicateFilterStage(
                FindingFilterStage::PathExclusion,
                new PathExclusionFilter(new PathMatcher($options->suppressPaths), $fileScope),
            );
        }
        $matcher = new NamespaceMatcher($options->suppressNamespaces);
        if (!$matcher->isEmpty()) {
            $stages[] = new PredicateFilterStage(
                FindingFilterStage::NamespaceExclusion,
                new NamespaceExclusionFilter($matcher, $fileScope),
            );
        }
        return $stages;
    }
}
