<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

final readonly class ConfiguredFindingExclusions
{
    /**
     * @param list<PathPattern> $suppressPaths
     * @param list<NamespacePattern> $suppressNamespaces
     */
    public function __construct(public array $suppressPaths = [], public array $suppressNamespaces = []) {}

    /**
     * @param list<PathPattern> $paths
     * @param list<NamespacePattern> $namespaces
     */
    public function withAdditional(array $paths, array $namespaces): self
    {
        return new self(
            self::uniquePatterns([...$this->suppressPaths, ...$paths]),
            self::uniquePatterns([...$this->suppressNamespaces, ...$namespaces]),
        );
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
