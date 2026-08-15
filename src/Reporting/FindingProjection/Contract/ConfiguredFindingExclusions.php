<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

final readonly class ConfiguredFindingExclusions
{
    /**
     * @param list<string> $excludePaths
     * @param list<string> $excludeNamespaces
     */
    public function __construct(public array $excludePaths = [], public array $excludeNamespaces = []) {}

    /**
     * @param list<string> $paths
     * @param list<string> $namespaces
     */
    public function withAdditional(array $paths, array $namespaces): self
    {
        return new self(
            array_values(array_unique([...$this->excludePaths, ...$paths])),
            array_values(array_unique([...$this->excludeNamespaces, ...$namespaces])),
        );
    }
}
