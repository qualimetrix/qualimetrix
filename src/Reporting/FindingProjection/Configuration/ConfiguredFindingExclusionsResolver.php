<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;

final class ConfiguredFindingExclusionsResolver implements ConfiguredFindingExclusionsResolverInterface
{
    public function resolve(ConfigurationDocument $document): ConfiguredFindingExclusions
    {
        $paths = [];
        foreach ($document->contributions(ConfigSchema::EXCLUDE_PATHS) as $value) {
            if (\is_array($value)) {
                array_push($paths, ...array_filter($value, is_string(...)));
            }
        }
        $namespaces = [];
        foreach ($document->contributions(ConfigSchema::EXCLUDE_NAMESPACES) as $value) {
            if (\is_array($value)) {
                array_push($namespaces, ...array_filter($value, is_string(...)));
            }
        }

        return new ConfiguredFindingExclusions(array_values(array_unique($paths)), array_values(array_unique($namespaces)));
    }
}
