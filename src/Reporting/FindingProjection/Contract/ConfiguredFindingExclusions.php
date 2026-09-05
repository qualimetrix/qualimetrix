<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

final readonly class ConfiguredFindingExclusions
{
    /**
     * @param list<string> $suppressPaths
     * @param list<string> $suppressNamespaces
     */
    public function __construct(public array $suppressPaths = [], public array $suppressNamespaces = []) {}

    /**
     * @param list<string> $paths
     * @param list<string> $namespaces
     */
    public function withAdditional(array $paths, array $namespaces): self
    {
        return new self(
            array_values(array_unique([...$this->suppressPaths, ...$paths])),
            array_values(array_unique([...$this->suppressNamespaces, ...$namespaces])),
        );
    }
}
