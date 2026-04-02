<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

/**
 * Mutable storage for rule options from config files and CLI.
 *
 * Holds per-rule options from two sources (config file and CLI) and manages
 * the namespace exclusion provider. This is the runtime state that gets
 * configured during the configuration pipeline and reset between runs.
 */
final class RuleOptionsRegistry
{
    /**
     * @var array<string, mixed> Rule options from config file (values may be arrays or scalars)
     */
    private array $configFileOptions = [];

    /**
     * @var array<string, array<string, mixed>> Rule options from CLI
     */
    private array $cliOptions = [];

    public function __construct(
        private readonly RuleNamespaceExclusionProvider $exclusionProvider = new RuleNamespaceExclusionProvider(),
        private readonly RulePathExclusionProvider $pathExclusionProvider = new RulePathExclusionProvider(),
    ) {}

    /**
     * Sets rule options from config file.
     *
     * Values may be arrays (normal config), or scalars (e.g. `false` to disable a rule).
     * Scalar values are normalized to arrays in RuleOptionsFactory::create().
     *
     * @param array<string, mixed> $options
     */
    public function setConfigFileOptions(array $options): void
    {
        $this->configFileOptions = $options;
    }

    /**
     * Gets rule options from config file.
     *
     * @return array<string, mixed>
     */
    public function getConfigFileOptions(): array
    {
        return $this->configFileOptions;
    }

    /**
     * Adds a CLI option for a specific rule.
     */
    public function addCliOption(string $ruleName, string $option, mixed $value): void
    {
        if (!isset($this->cliOptions[$ruleName])) {
            $this->cliOptions[$ruleName] = [];
        }

        $this->cliOptions[$ruleName][$option] = $value;
    }

    /**
     * Sets multiple CLI options for a rule.
     *
     * @param array<string, mixed> $options
     */
    public function setCliOptions(string $ruleName, array $options): void
    {
        $this->cliOptions[$ruleName] = $options;
    }

    /**
     * Gets all CLI options.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCliOptions(): array
    {
        return $this->cliOptions;
    }

    /**
     * Resets CLI options only, preserving config file options.
     *
     * Must be called between runs to prevent options from a previous run
     * leaking into the next one.
     */
    public function resetCliOptions(): void
    {
        $this->cliOptions = [];
    }

    /**
     * Resets all runtime state between analysis runs.
     *
     * Clears CLI options and exclusion providers while preserving config file options
     * (which are re-set later via setConfigFileOptions()).
     */
    public function resetRuntimeState(): void
    {
        $this->cliOptions = [];
        $this->exclusionProvider->reset();
        $this->pathExclusionProvider->reset();
    }

    /**
     * Clears all options (useful for testing).
     */
    public function reset(): void
    {
        $this->configFileOptions = [];
        $this->cliOptions = [];
        $this->exclusionProvider->reset();
        $this->pathExclusionProvider->reset();
    }

    public function getExclusionProvider(): RuleNamespaceExclusionProvider
    {
        return $this->exclusionProvider;
    }

    public function getPathExclusionProvider(): RulePathExclusionProvider
    {
        return $this->pathExclusionProvider;
    }
}
