<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider;
use Qualimetrix\Analysis\Finding\Exclusion\RulePathExclusionProvider;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Mutable storage for rule options from config files and CLI.
 *
 * Holds per-rule options from two sources (config file and CLI) and manages
 * the namespace exclusion provider. This is the runtime state that gets
 * configured during the configuration pipeline and reset between runs.
 */
final class RuleOptionsRegistry implements RuleConfigurationInterface
{
    /**
     * @var array<string, mixed> Rule options from config file (values may be arrays or scalars)
     */
    private array $configFileOptions = [];

    /**
     * @var array<string, array<string, mixed>> Rule options from CLI
     */
    private array $cliOptions = [];

    private RuleSelection $selection;

    private bool $capturesExcludedFindings = false;

    public function __construct(
        private readonly RuleNamespaceExclusionProvider $exclusionProvider = new RuleNamespaceExclusionProvider(),
        private readonly RulePathExclusionProvider $pathExclusionProvider = new RulePathExclusionProvider(),
    ) {
        $this->selection = new RuleSelection();
    }

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

    public function replace(FindingConfiguration $configuration): void
    {
        $this->configFileOptions = $configuration->ruleOptions->rules;
        $this->cliOptions = $configuration->cliOverrides->options;
        $this->selection = $configuration->selection;
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

    public function configFileOptions(): array
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

    public function configureCli(string $ruleName, array $options): void
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

    public function cliOptions(): array
    {
        return $this->cliOptions;
    }

    public function all(): array
    {
        return array_replace_recursive($this->configFileOptions, $this->cliOptions);
    }

    public function configureSelection(RuleSelection $selection): void
    {
        $this->selection = $selection;
    }

    public function selection(): RuleSelection
    {
        return $this->selection;
    }

    /**
     * Reads the same two spellings {@see RuleOptionsFactory} normalises —
     * the scalar `false` and the explicit `enabled: false` key — off the
     * merged config, so the answer cannot drift from what the rule's own
     * options object will decide.
     */
    public function isRuleDisabledByOptions(string $ruleName): bool
    {
        $config = $this->all()[$ruleName] ?? null;

        if ($config === false) {
            return true;
        }

        if (!\is_array($config) || !\array_key_exists(RuleOptionKey::ENABLED, $config)) {
            return false;
        }

        return !$config[RuleOptionKey::ENABLED];
    }

    public function captureExcludedFindings(): void
    {
        $this->capturesExcludedFindings = true;
    }

    public function capturesExcludedFindings(): bool
    {
        return $this->capturesExcludedFindings;
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
        $this->selection = new RuleSelection();
    }

    /**
     * Resets all runtime state between analysis runs.
     *
     * Clears all invocation state before the next configuration is resolved.
     */
    public function resetRuntimeState(): void
    {
        $this->configFileOptions = [];
        $this->cliOptions = [];
        $this->selection = new RuleSelection();
        $this->capturesExcludedFindings = false;
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
        $this->selection = new RuleSelection();
        $this->capturesExcludedFindings = false;
        $this->exclusionProvider->reset();
        $this->pathExclusionProvider->reset();
    }

    public function configureNamespaceExclusions(string $ruleName, mixed $patterns): void
    {
        $this->exclusionProvider->configureExclusions($ruleName, $patterns);
    }

    public function configureNamespaceChannelExclusions(string $ruleName, mixed $patterns): void
    {
        $this->exclusionProvider->configureChannelExclusions($ruleName, $patterns);
    }

    public function configurePathExclusions(string $ruleName, array $patterns): void
    {
        $this->pathExclusionProvider->setExclusions($ruleName, $patterns);
    }

    public function isNamespaceExcluded(string $ruleName, string $namespace): bool
    {
        return $this->exclusionProvider->isExcluded($ruleName, $namespace);
    }

    public function isNamespaceChannelExcluded(string $ruleName, FindingChannel $channel, string $namespace): bool
    {
        return $this->exclusionProvider->isChannelExcluded($ruleName, $channel, $namespace);
    }

    public function isPathExcluded(string $ruleName, RelativePath $path): bool
    {
        return $this->pathExclusionProvider->isExcluded($ruleName, $path);
    }
}
