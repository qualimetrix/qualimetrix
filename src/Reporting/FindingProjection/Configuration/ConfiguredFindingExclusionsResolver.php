<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Configuration;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfiguredValueWriters;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
use Qualimetrix\Analysis\Finding\Contract\GlobalValueWriters;
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
            new GlobalValueWriters(
                $this->writers($document, ConfigSchema::SUPPRESS_PATHS, $this->decoder->decodePath(...)),
                $this->writers($document, ConfigSchema::SUPPRESS_NAMESPACES, $this->decoder->decodeNamespace(...)),
            ),
        );
    }

    /**
     * Every layer that wrote a value, not only the lowest: the set keeps one
     * element per spelling and drops the rest from the list's items.
     *
     * @param Closure(mixed, ConfigurationOrigin, list<string>): (PathPattern|NamespacePattern) $decode
     *
     * @return array<string, ConfiguredValueWriters>
     */
    private function writers(ConfigurationDocument $document, string $key, Closure $decode): array
    {
        $entries = self::entries($document, $key);
        if ($entries === null) {
            return [];
        }
        if (!$entries instanceof ResolvedWriteHistoryInterface) {
            throw new LogicException(\sprintf('The list "%s" lost its authored write history.', $key));
        }

        $writers = [];
        foreach ($entries->writes() as $write) {
            $writtenBy = new ConfiguredValueWriters([$write['provenance']->origin]);
            foreach ($write['value'] as $index => $raw) {
                $display = $decode($raw, $write['provenance']->origin, $write['provenance']->path ?? [$key, (string) $index])->definition->display();
                $writers[$display] = isset($writers[$display]) ? $writers[$display]->with($writtenBy) : $writtenBy;
            }
        }

        return $writers;
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
