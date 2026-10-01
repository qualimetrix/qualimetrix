<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider;
use Qualimetrix\Analysis\Finding\Exclusion\RulePathExclusionProvider;
use Qualimetrix\Core\Path\RelativePath;

/** Per-invocation resolved options, enablement and exclusions. */
final class RuleOptionsRegistry implements RuleConfigurationInterface
{
    private FindingConfiguration $configuration;

    private bool $capturesExcludedFindings = false;

    public function __construct(
        private readonly RuleNamespaceExclusionProvider $exclusionProvider = new RuleNamespaceExclusionProvider(),
        private readonly RulePathExclusionProvider $pathExclusionProvider = new RulePathExclusionProvider(),
    ) {
        $this->configuration = FindingConfiguration::none();
    }

    public function replace(FindingConfiguration $configuration): void
    {
        $snapshot = $configuration->resolvedOptions
            ?? throw new LogicException('Rule options must be built before runtime configuration is committed.');
        if ($configuration->enablement === null || $configuration->channels === null) {
            throw new LogicException('Rule enablement and its channel universe must be resolved before runtime configuration is committed.');
        }
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

    public function enablement(): ?RuleEnablement
    {
        return $this->configuration->enablement;
    }

    public function channelUniverse(): ChannelUniverseInterface
    {
        return $this->configuration->channels
            ?? throw new LogicException('Rule channels are unavailable before analysis preflight.');
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
