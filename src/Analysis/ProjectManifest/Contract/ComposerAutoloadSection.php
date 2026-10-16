<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

/** Accepted Composer autoload records and the integrity of their section. */
final readonly class ComposerAutoloadSection
{
    /** @param array{'psr-4'?: array<string, list<string>>, 'psr-0'?: array<string, list<string>>, classmap?: list<string>, files?: list<string>} $mappings */
    public function __construct(public array $mappings, public bool $complete) {}

    /** @return list<string> */
    public function targets(): array
    {
        $paths = [];
        foreach (['psr-4', 'psr-0'] as $kind) {
            foreach ($this->mappings[$kind] ?? [] as $prefixPaths) {
                array_push($paths, ...$prefixPaths);
            }
        }

        return array_values(array_unique([...$paths, ...($this->mappings['classmap'] ?? []), ...($this->mappings['files'] ?? [])]));
    }

    /** @return array<string, list<string>> */
    public function psr4Roots(): array
    {
        return $this->mappings['psr-4'] ?? [];
    }
}
