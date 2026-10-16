<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
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
        foreach (self::entries($document, ConfigSchema::SUPPRESS_PATHS)?->items() ?? [] as $index => $entry) {
            $writer = $entry->contributors()[0];
            $patterns[] = $this->decoder->decodePath(
                $entry->plain(),
                $writer->origin,
                $writer->path ?? [ConfigSchema::SUPPRESS_PATHS, (string) $index],
            );
        }

        return $patterns;
    }

    /** @return list<NamespacePattern> */
    private function suppressedNamespaces(ConfigurationDocument $document): array
    {
        $patterns = [];
        foreach (self::entries($document, ConfigSchema::SUPPRESS_NAMESPACES)?->items() ?? [] as $index => $entry) {
            $writer = $entry->contributors()[0];
            $patterns[] = $this->decoder->decodeNamespace(
                $entry->plain(),
                $writer->origin,
                $writer->path ?? [ConfigSchema::SUPPRESS_NAMESPACES, (string) $index],
            );
        }

        return $patterns;
    }

    private static function entries(ConfigurationDocument $document, string $key): ?ResolvedListInterface
    {
        $entries = $document->resolved()->get($key);

        return $entries === null ? null : ($entries instanceof ResolvedListInterface ? $entries : throw new LogicException(
            \sprintf('The document declares a list here, but resolved a %s.', $entries::class),
        ));
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
