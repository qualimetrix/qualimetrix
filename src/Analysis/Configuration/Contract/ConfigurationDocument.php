<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract;

/** Immutable ordered configuration contributions for feature-owned resolution. */
final readonly class ConfigurationDocument
{
    /** @param list<array<string, mixed>> $sources */
    public function __construct(private array $sources) {}

    /** @return list<mixed> */
    public function contributions(string $topLevelKey): array
    {
        $contributions = [];
        foreach ($this->sources as $source) {
            if (\array_key_exists($topLevelKey, $source)) {
                $contributions[] = $source[$topLevelKey];
            }
        }

        return $contributions;
    }
}
