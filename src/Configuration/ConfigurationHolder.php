<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use RuntimeException;

/**
 * Default implementation of ConfigurationProviderInterface.
 *
 * Stores configuration in memory, allowing it to be set at runtime
 * (e.g., after CLI parsing) and accessed by services.
 */
final class ConfigurationHolder implements ConfigurationProviderInterface
{
    private ?AnalysisConfiguration $configuration = null;

    /** @var array<string, mixed> */
    private array $ruleOptions = [];

    public function getConfiguration(): AnalysisConfiguration
    {
        if ($this->configuration === null) {
            throw new RuntimeException('Configuration not set. Call setConfiguration() first.');
        }

        return $this->configuration;
    }

    public function setConfiguration(AnalysisConfiguration $config): void
    {
        $this->configuration = $config;
    }

    public function getRuleOptions(): array
    {
        return $this->ruleOptions;
    }

    public function setRuleOptions(array $options): void
    {
        $this->ruleOptions = $options;
    }

    public function hasConfiguration(): bool
    {
        return $this->configuration !== null;
    }
}
