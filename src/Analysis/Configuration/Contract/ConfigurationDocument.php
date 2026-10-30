<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfiguredValueWriters;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * Immutable configuration for feature-owned resolution: the resolved document
 * the engine composed from the authored layers, plus the two discovery facts
 * that composer contributes outside the authored document.
 */
final readonly class ConfigurationDocument
{
    private ResolvedDocument $resolved;

    /**
     * `file` is the identity of the file a source was read from, `name` its description.
     *
     * @param list<array{source: string, values: array<string, mixed>, file?: ?FileIdentity, name?: string}> $sources
     */
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

    /**
     * The file this configuration was read from that has the given identity,
     * as a refusal names it (`configuration file "base.yaml" (imported by …)`),
     * or null; the lowest-precedence source wins when a file is in two bands.
     */
    public function fileReadAs(?FileIdentity $identity): ?string
    {
        if ($identity === null) {
            return null;
        }
        foreach ($this->sources as $source) {
            if (($source['file'] ?? null)?->sameAs($identity) === true) {
                return $source['name'] ?? $source['source'];
            }
        }

        return null;
    }

    /** The sentence a finding ends with to name the sources that wrote the value at this path; null when none did. */
    public function writtenBy(string $root, string ...$path): ?string
    {
        $node = $this->resolved->get($root, ...$path);

        return $node === null ? null : ConfiguredValueWriters::fromProvenance($node->contributors())->sentence();
    }

    /** {@see fileReadAs()} for the regular file `$path` leads to now; null when it leads to none. */
    public function regularFileReadAs(string $path): ?string
    {
        [$stat] = NativeCall::attempt(static fn(): array|false => stat($path));

        return $this->fileReadAs(FileIdentity::ofRegularFile($stat));
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
