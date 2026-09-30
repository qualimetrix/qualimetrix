<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
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
    /** Rule options from the config file and the CLI, and the rule selection. */
    private FindingConfiguration $configuration;

    private bool $capturesExcludedFindings = false;

    public function __construct(
        private readonly RuleNamespaceExclusionProvider $exclusionProvider = new RuleNamespaceExclusionProvider(),
        private readonly RulePathExclusionProvider $pathExclusionProvider = new RulePathExclusionProvider(),
    ) {
        $this->configuration = FindingConfiguration::none();
    }

    /**
     * The one door the product configures a run through. Every narrower
     * setter below is written in terms of it, so a field it learns to set is
     * set by all of them rather than left behind by the ones only tests call.
     */
    public function replace(FindingConfiguration $configuration): void
    {
        $snapshot = $configuration->resolvedOptions
            ?? throw new LogicException('Rule options must be built before runtime configuration is committed.');
        $this->exclusionProvider->reset();
        $this->pathExclusionProvider->reset();
        foreach ($snapshot->all() as $producer => $options) {
            $suppression = $snapshot->suppressionFor($producer);
            $this->configureNamespaceExclusions($producer, $suppression->namespaces);
            $this->configureNamespaceChannelExclusions($producer, $suppression->namespaceChannels);
            $this->configurePathExclusions($producer, $suppression->paths);
        }
        $this->configuration = $configuration;
    }

    public function resolvedOptions(): ResolvedRuleOptions
    {
        return $this->configuration->resolvedOptions
            ?? throw new LogicException('Rule options are unavailable before analysis preflight.');
    }

    /** @param class-string<RuleOptionsInterface> $optionsClass */
    public function optionsFor(string $producer, string $optionsClass): RuleOptionsInterface
    {
        $options = $this->resolvedOptions()->for($producer);
        if (!$options instanceof $optionsClass) {
            throw new LogicException(\sprintf('Resolved options for "%s" must be %s.', $producer, $optionsClass));
        }
        return $options;
    }

    /**
     * Gets rule options from config file.
     *
     * @return array<string, mixed>
     */
    public function configFileOptions(): array
    {
        return $this->configuration->ruleOptions->rules;
    }

    /**
     * Gets all CLI options.
     *
     * @return array<string, array<string, mixed>>
     */
    public function cliOptions(): array
    {
        return $this->configuration->cliOverrides->options;
    }

    public function all(): array
    {
        return array_replace_recursive($this->configFileOptions(), $this->cliOptions());
    }

    public function selection(): RuleSelection
    {
        return $this->configuration->selection;
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
     * Resets all runtime state between analysis runs.
     *
     * Clears all invocation state before the next configuration is resolved.
     */
    public function resetRuntimeState(): void
    {
        $this->configuration = FindingConfiguration::none();
        $this->capturesExcludedFindings = false;
        $this->exclusionProvider->reset();
        $this->pathExclusionProvider->reset();
    }

    public function configureNamespaceExclusions(string $ruleName, array $patterns): void
    {
        $this->exclusionProvider->setExclusions($ruleName, $patterns);
    }

    public function configureNamespaceChannelExclusions(string $ruleName, array $patterns): void
    {
        foreach ($patterns as $selector => $namespacePatterns) {
            $this->exclusionProvider->setChannelExclusions($ruleName, $selector, $namespacePatterns);
        }
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

    public function namespaceExclusions(string $ruleName): array
    {
        return $this->exclusionProvider->getExclusions($ruleName);
    }

    public function namespaceChannelExclusions(string $ruleName): array
    {
        return $this->exclusionProvider->getChannelExclusions($ruleName);
    }

    public function pathExclusions(string $ruleName): array
    {
        return $this->pathExclusionProvider->getExclusions($ruleName);
    }
}
