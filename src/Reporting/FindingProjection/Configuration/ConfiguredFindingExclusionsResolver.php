<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;

final class ConfiguredFindingExclusionsResolver implements ConfiguredFindingExclusionsResolverInterface
{
    public function resolve(ConfigurationDocument $document): ConfiguredFindingExclusions
    {
        $paths = self::accumulated($document, ConfigSchema::SUPPRESS_PATHS);
        $namespaces = self::accumulated($document, ConfigSchema::SUPPRESS_NAMESPACES);

        return new ConfiguredFindingExclusions(array_values(array_unique($paths)), array_values(array_unique($namespaces)));
    }

    /**
     * A map here used to reach `array_push()` as named arguments and abort the
     * run with an internal error; a list of non-strings was dropped without a
     * word. Both are the same mistake and the same answer.
     *
     * @return list<string>
     */
    private static function accumulated(ConfigurationDocument $document, string $key): array
    {
        $values = [];

        foreach ($document->contributions($key) as $contribution) {
            if (!\is_array($contribution) || ($contribution !== [] && !array_is_list($contribution))) {
                throw ConfigurationRefusal::aboutResolvedInput(
                    \sprintf(
                        'Invalid value for "%s": expected a list of entries, got %s.',
                        $key,
                        \is_array($contribution) ? 'a map' : get_debug_type($contribution),
                    ),
                    $key,
                );
            }

            foreach ($contribution as $entry) {
                if (!\is_string($entry)) {
                    throw ConfigurationRefusal::aboutResolvedInput(
                        \sprintf(
                            'Invalid entry in "%s": every entry must be a string, got %s.',
                            $key,
                            get_debug_type($entry),
                        ),
                        $key,
                    );
                }

                $values[] = $entry;
            }
        }

        return $values;
    }
}
