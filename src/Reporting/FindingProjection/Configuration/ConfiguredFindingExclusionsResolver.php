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
        return new ConfiguredFindingExclusions(
            array_values(array_unique(self::suppressedPaths($document))),
            array_values(array_unique(self::suppressedNamespaces($document))),
        );
    }

    /**
     * A directory may legitimately be called `2024`, and YAML hands such an
     * unquoted segment over as an int — so the entry is converted, which is the
     * position the product already takes for `--exclude=7`. Everything a path
     * cannot be is still refused.
     *
     * @return list<string>
     */
    private static function suppressedPaths(ConfigurationDocument $document): array
    {
        $values = [];

        foreach (self::entries($document, ConfigSchema::SUPPRESS_PATHS) as $entry) {
            $values[] = \is_int($entry) || \is_float($entry)
                ? (string) $entry
                : self::acceptedString($entry, ConfigSchema::SUPPRESS_PATHS);
        }

        return $values;
    }

    /**
     * No conversion here, and for a reason: a namespace segment cannot start
     * with a digit, so a bare number is a mistake rather than a name written
     * without quotes.
     *
     * @return list<string>
     */
    private static function suppressedNamespaces(ConfigurationDocument $document): array
    {
        $values = [];

        foreach (self::entries($document, ConfigSchema::SUPPRESS_NAMESPACES) as $entry) {
            $values[] = self::acceptedString($entry, ConfigSchema::SUPPRESS_NAMESPACES);
        }

        return $values;
    }

    /**
     * Every entry every contribution wrote, in order, once the container of
     * each is known to be a list. A map here used to reach `array_push()` as
     * named arguments and abort the run with an internal error.
     *
     * @return iterable<mixed>
     */
    private static function entries(ConfigurationDocument $document, string $key): iterable
    {
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

            yield from $contribution;
        }
    }

    /** A list of non-strings used to be dropped without a word. */
    private static function acceptedString(mixed $entry, string $key): string
    {
        if (\is_string($entry)) {
            return $entry;
        }

        throw ConfigurationRefusal::aboutResolvedInput(
            \sprintf(
                'Invalid entry in "%s": every entry must be a string, got %s.',
                $key,
                get_debug_type($entry),
            ),
            $key,
        );
    }
}
