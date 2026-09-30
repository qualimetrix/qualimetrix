<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * Immutable configuration for feature-owned resolution: the resolved document
 * the engine composed from the authored layers, plus the two discovery facts
 * that composer contributes outside the authored document.
 */
final readonly class ConfigurationDocument
{
    private ResolvedDocument $resolved;

    /** @param list<array{source: string, values: array<string, mixed>}> $sources */
    public function __construct(
        private array $sources,
        private AbsolutePath $workingDirectory,
        ?ResolvedDocument $resolved = null,
        /** @var list<ConfigurationDiagnostic> */
        private array $sourceDiagnostics = [],
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
        return [...$this->resolved->diagnostics(), ...$this->sourceDiagnostics];
    }

    /** @return list<mixed> Ordered raw `rules:` values, pending Finding's resolved-rule migration. */
    public function ruleContributions(): array
    {
        return $this->valuesFor(ConfigSchema::RULES);
    }

    /** @return list<mixed> Ordered raw `only_rules:` values, pending Finding's resolved-rule migration. */
    public function onlyRuleContributions(): array
    {
        return $this->valuesFor(ConfigSchema::ONLY_RULES);
    }

    /** @return list<mixed> Ordered raw `disabled_rules:` values, pending Finding's resolved-rule migration. */
    public function disabledRuleContributions(): array
    {
        return $this->valuesFor(ConfigSchema::DISABLED_RULES);
    }

    /** @return list<string> */
    public function discoveredProductionAutoloadTargets(): array
    {
        return $this->discoveryTargets(ConfigSchema::DISCOVERED_AUTOLOAD_PATHS);
    }

    /** @return list<string> */
    public function discoveredDevelopmentAutoloadTargets(): array
    {
        return $this->discoveryTargets(ConfigSchema::DISCOVERED_AUTOLOAD_DEV_PATHS);
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

    /** @return list<mixed> */
    private function valuesFor(string $key): array
    {
        $values = [];
        foreach ($this->sources as $source) {
            if (\array_key_exists($key, $source['values'])) {
                $values[] = $source['values'][$key];
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function discoveryTargets(string $key): array
    {
        $values = $this->valuesFor($key);
        if ($values === []) {
            return [];
        }

        $targets = $values[array_key_last($values)];
        if (!\is_array($targets) || !array_is_list($targets) || array_filter($targets, is_string(...)) !== $targets) {
            throw new LogicException(\sprintf('Composer discovery contributed an invalid "%s" target list.', $key));
        }

        return $targets;
    }
}
