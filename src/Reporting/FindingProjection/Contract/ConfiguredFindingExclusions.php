<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

final readonly class ConfiguredFindingExclusions
{
    /**
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     * @param array<string, ConfiguredValueWriters> $pathWriters keyed by the pattern's display; a pattern
     *                                                           with no entry has unknown writers
     * @param array<string, ConfiguredValueWriters> $namespaceWriters keyed likewise
     */
    public function __construct(
        public array $suppressPaths = [],
        public array $suppressNamespaces = [],
        public array $pathWriters = [],
        public array $namespaceWriters = [],
    ) {}

    /**
     * @param list<PathPattern> $paths
     * @param list<NamespacePattern> $namespaces
     * @param array<string, ConfiguredValueWriters> $pathWriters writers of $paths, keyed by display
     * @param array<string, ConfiguredValueWriters> $namespaceWriters writers of $namespaces, keyed by display
     */
    public function withAdditional(array $paths, array $namespaces, array $pathWriters = [], array $namespaceWriters = []): self
    {
        return new self(
            self::uniquePatterns([...$this->suppressPaths, ...$paths]),
            self::uniquePatterns([...$this->suppressNamespaces, ...$namespaces]),
            self::united($this->pathWriters, $pathWriters),
            self::united($this->namespaceWriters, $namespaceWriters),
        );
    }

    /**
     * An equal value written by both sides is one element with both writers.
     *
     * @param array<string, ConfiguredValueWriters> $own
     * @param array<string, ConfiguredValueWriters> $additional
     *
     * @return array<string, ConfiguredValueWriters>
     */
    private static function united(array $own, array $additional): array
    {
        foreach ($additional as $display => $writers) {
            $own[$display] = isset($own[$display]) ? $own[$display]->with($writers) : $writers;
        }

        return $own;
    }

    /**
     * @template T of PathPattern|NamespacePattern
     *
     * @param list<T> $patterns
     *
     * @return list<T>
     */
    private static function uniquePatterns(array $patterns): array
    {
        $unique = [];
        foreach ($patterns as $pattern) {
            $unique[$pattern->definition->display()] = $pattern;
        }

        return array_values($unique);
    }
}
