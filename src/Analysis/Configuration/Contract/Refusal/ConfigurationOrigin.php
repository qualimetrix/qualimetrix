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
        private ?string $authoredExpression,
    ) {}

    public static function of(ConfigurationSource $source, ?string $locator = null): self
    {
        return new self($source, $locator, null, null);
    }

    /** This source as read through `$importer`, which named it. */
    public function importedThrough(self $importer): self
    {
        return new self($this->source, $this->locator, $importer, $this->authoredExpression);
    }

    /** The same source with a narrower name — an option within the command line. */
    public function locatedAt(string $locator): self
    {
        return new self($this->source, $locator, $this->importer, $this->authoredExpression);
    }

    public function locatedAtAuthoredWrite(string $locator, string $expression): self
    {
        return new self($this->source, $locator, $this->importer, $expression);
    }

    public function authoredExpression(): ?string
    {
        return $this->authoredExpression;
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
        $own = $this->locator === null ? $this->unnamed() : $this->named($this->locator);
        if ($this->source === ConfigurationSource::CommandLine && $this->authoredExpression !== null) {
            $own .= \sprintf(' (written as %s)', $this->authoredExpression);
        }

        return $this->importer === null ? $own : \sprintf('%s (imported by %s)', $own, $this->importer->describe());
    }

    private function unnamed(): string
    {
        return match ($this->source) {
            ConfigurationSource::Defaults => 'the built-in defaults',
            ConfigurationSource::ComposerJson => 'composer.json',
            ConfigurationSource::Preset => 'preset "?"',
            ConfigurationSource::ConfigFile => 'configuration file "?"',
            ConfigurationSource::CommandLine => 'the command line',
            ConfigurationSource::Environment => 'the environment',
            ConfigurationSource::BaselineFile => 'baseline file "?"',
            ConfigurationSource::Resolved => 'the merged configuration',
        };
    }

    private function named(string $locator): string
    {
        return match ($this->source) {
            ConfigurationSource::Defaults => 'the built-in defaults',
            ConfigurationSource::ComposerJson => \sprintf('"%s"', $locator),
            ConfigurationSource::Preset => \sprintf('preset "%s"', $locator),
            ConfigurationSource::ConfigFile => \sprintf('configuration file "%s"', $locator),
            ConfigurationSource::CommandLine => \sprintf('option %s', $locator),
            ConfigurationSource::Environment => \sprintf('environment variable %s', $locator),
            ConfigurationSource::BaselineFile => \sprintf('baseline file "%s"', $locator),
            ConfigurationSource::Resolved => 'the merged configuration',
        };
    }
}
