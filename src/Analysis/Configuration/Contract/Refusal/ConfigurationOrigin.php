<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

/**
 * One source instance a configuration value came from: its kind, its name
 * (file path, preset name, option name) and, for a file another source
 * imported, the importing source — so a refusal can name both files.
 */
final readonly class ConfigurationOrigin
{
    private function __construct(
        private ConfigurationSource $source,
        private ?string $locator,
        private ?self $importer,
    ) {}

    public static function of(ConfigurationSource $source, ?string $locator = null): self
    {
        return new self($source, $locator, null);
    }

    /** This source as read through `$importer`, which named it. */
    public function importedThrough(self $importer): self
    {
        return new self($this->source, $this->locator, $importer);
    }

    /** The same source with a narrower name — an option within the command line. */
    public function locatedAt(string $locator): self
    {
        return new self($this->source, $locator, $this->importer);
    }

    public function source(): ConfigurationSource
    {
        return $this->source;
    }

    /**
     * File path, preset name, or CLI/config-key name. Usually null for
     * {@see ConfigurationSource::Resolved} — a merged value with no single
     * originating file or option left to name — except where a caller
     * still has the resolved key name at hand and passes it as a
     * diagnostic hint (e.g. `ConfigSchema::FAIL_ON`, `ConfigSchema::MEMORY_LIMIT`).
     */
    public function locator(): ?string
    {
        return $this->locator;
    }

    public function importer(): ?self
    {
        return $this->importer;
    }

    /** The source as a sentence fragment: `preset "strict"`, `option --fail-on`. */
    public function describe(): string
    {
        $own = match ($this->source) {
            ConfigurationSource::Defaults => 'the built-in defaults',
            ConfigurationSource::ComposerJson => $this->locator === null ? 'composer.json' : \sprintf('"%s"', $this->locator),
            ConfigurationSource::Preset => \sprintf('preset "%s"', $this->locator ?? '?'),
            ConfigurationSource::ConfigFile => \sprintf('configuration file "%s"', $this->locator ?? '?'),
            ConfigurationSource::CommandLine => $this->locator === null ? 'the command line' : \sprintf('option %s', $this->locator),
            ConfigurationSource::BaselineFile => \sprintf('baseline file "%s"', $this->locator ?? '?'),
            ConfigurationSource::Resolved => 'the merged configuration',
        };

        return $this->importer === null ? $own : \sprintf('%s (imported by %s)', $own, $this->importer->describe());
    }
}
