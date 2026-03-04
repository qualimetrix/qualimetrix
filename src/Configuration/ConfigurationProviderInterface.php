<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration;

use RuntimeException;

/**
 * Provides runtime-configurable access to analysis configuration.
 *
 * This interface allows setting configuration at runtime (e.g., from CLI options)
 * instead of requiring synthetic DI services.
 */
interface ConfigurationProviderInterface
{
    /**
     * Gets the current analysis configuration.
     *
     * @throws RuntimeException if configuration not set
     */
    public function getConfiguration(): AnalysisConfiguration;

    /**
     * Sets the analysis configuration.
     */
    public function setConfiguration(AnalysisConfiguration $config): void;

    /**
     * Gets rule-specific options.
     *
     * @return array<string, mixed>
     */
    public function getRuleOptions(): array;

    /**
     * Sets rule-specific options.
     *
     * @param array<string, mixed> $options
     */
    public function setRuleOptions(array $options): void;

    /**
     * Checks if configuration has been set.
     */
    public function hasConfiguration(): bool;
}
