<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract;

use Qualimetrix\Core\Path\AbsolutePath;

/** Immutable ordered configuration contributions for feature-owned resolution. */
final readonly class ConfigurationDocument
{
    /** @param list<array{source: string, values: array<string, mixed>}> $sources */
    public function __construct(
        private array $sources,
        private AbsolutePath $workingDirectory,
    ) {}

    /** @return list<mixed> */
    public function contributions(string $topLevelKey): array
    {
        $contributions = [];
        foreach ($this->sources as $source) {
            if (\array_key_exists($topLevelKey, $source['values'])) {
                $contributions[] = $source['values'][$topLevelKey];
            }
        }

        return $contributions;
    }

    /** @return list<string> */
    public function appliedSources(): array
    {
        return array_values(array_unique(array_column($this->sources, 'source')));
    }

    public function workingDirectory(): AbsolutePath
    {
        return $this->workingDirectory;
    }
}
