<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;

final class ConfiguredFindingExclusionsResolver implements ConfiguredFindingExclusionsResolverInterface
{
    public function __construct(private readonly SelectorYamlDecoder $decoder = new SelectorYamlDecoder()) {}

    public function resolve(ConfigurationDocument $document): ConfiguredFindingExclusions
    {
        return new ConfiguredFindingExclusions(
            $this->unique($this->suppressedPaths($document)),
            $this->unique($this->suppressedNamespaces($document)),
        );
    }

    /** @return list<PathPattern> */
    private function suppressedPaths(ConfigurationDocument $document): array
    {
        $patterns = [];
        foreach ($this->entries($document, ConfigSchema::SUPPRESS_PATHS) as $index => $entry) {
            $patterns[] = $this->decoder->decodePath(
                $entry,
                ConfigurationOrigin::of(ConfigurationSource::Resolved, ConfigSchema::SUPPRESS_PATHS),
                [ConfigSchema::SUPPRESS_PATHS, (string) $index],
            );
        }

        return $patterns;
    }

    /** @return list<NamespacePattern> */
    private function suppressedNamespaces(ConfigurationDocument $document): array
    {
        $patterns = [];
        foreach ($this->entries($document, ConfigSchema::SUPPRESS_NAMESPACES) as $index => $entry) {
            $patterns[] = $this->decoder->decodeNamespace(
                $entry,
                ConfigurationOrigin::of(ConfigurationSource::Resolved, ConfigSchema::SUPPRESS_NAMESPACES),
                [ConfigSchema::SUPPRESS_NAMESPACES, (string) $index],
            );
        }

        return $patterns;
    }

    /** @return iterable<mixed> */
    private function entries(ConfigurationDocument $document, string $key): iterable
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

    /**
     * @template T of PathPattern|NamespacePattern
     *
     * @param list<T> $patterns
     *
     * @return list<T>
     */
    private function unique(array $patterns): array
    {
        $unique = [];
        foreach ($patterns as $pattern) {
            $unique[$pattern->definition->display()] = $pattern;
        }

        return array_values($unique);
    }
}
