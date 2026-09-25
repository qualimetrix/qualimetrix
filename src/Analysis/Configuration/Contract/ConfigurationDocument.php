<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract;

use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * Immutable configuration for feature-owned resolution: the ordered
 * contributions owners still fold themselves, and the resolved document the
 * engine composed from the layers handed over as written.
 */
final readonly class ConfigurationDocument
{
    private ResolvedDocument $resolved;

    /** @param list<array{source: string, values: array<string, mixed>}> $sources */
    public function __construct(
        private array $sources,
        private AbsolutePath $workingDirectory,
        ?ResolvedDocument $resolved = null,
    ) {
        $this->resolved = $resolved ?? ResolvedDocument::empty();
    }

    /** The merged document with provenance; a section is present once its owner declares it. */
    public function resolved(): ResolvedDocument
    {
        return $this->resolved;
    }

    /** @return list<ConfigurationDiagnostic> */
    public function diagnostics(): array
    {
        return $this->resolved->diagnostics();
    }

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
