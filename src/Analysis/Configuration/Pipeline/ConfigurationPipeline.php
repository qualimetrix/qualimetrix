<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory;

/**
 * Configuration resolution pipeline.
 *
 * Collects configuration from multiple stages (defaults, composer, config file, cli)
 * and merges them according to priority order.
 *
 * The architecture configuration factory may emit warnings during resolution
 * (e.g. wildcard self-allow detection). Because {@see resolve()} runs before
 * {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator::configureLogger()}
 * has wired up the user-facing logger, those warnings are captured in
 * {@see TransitionalResolvedConfiguration::$deferredWarnings} via
 * {@see \Qualimetrix\Architecture\Configuration\ArchitectureFactoryResult}.
 * {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator} drains them
 * to the configured logger once the holder is populated.
 */
final class ConfigurationPipeline implements ConfigurationPipelineInterface
{
    /** @var list<ConfigurationStageInterface> */
    private array $stages = [];

    public function __construct(
        private readonly ArchitectureConfigurationFactory $architectureConfigurationFactory = new ArchitectureConfigurationFactory(),
    ) {}

    public function resolve(ConfigurationContext $context): TransitionalResolvedConfiguration
    {
        // Sort by priority (lower = earlier)
        $stages = $this->stages;
        usort(
            $stages,
            static fn(ConfigurationStageInterface $a, ConfigurationStageInterface $b): int =>
            $a->priority() <=> $b->priority(),
        );

        // Collect layers
        $merged = [];
        $appliedSources = [];
        foreach ($stages as $stage) {
            $layer = $stage->apply($context);
            if ($layer !== null) {
                $appliedSources[] = $layer->source;
                $merged = ConfigurationMerger::merge($merged, $layer->values);
            }
        }

        return $this->buildResolved($merged, $appliedSources);
    }

    public function addStage(ConfigurationStageInterface $stage): void
    {
        $this->stages[] = $stage;
    }

    /**
     * @return list<ConfigurationStageInterface>
     */
    public function stages(): array
    {
        $stages = $this->stages;
        usort(
            $stages,
            static fn(ConfigurationStageInterface $a, ConfigurationStageInterface $b): int =>
            $a->priority() <=> $b->priority(),
        );
        return $stages;
    }

    /**
     * @param array<string, mixed> $merged
     * @param list<string> $appliedSources
     */
    private function buildResolved(array $merged, array $appliedSources): TransitionalResolvedConfiguration
    {
        // The architecture factory emits PSR-3-shaped records (e.g. wildcard
        // self-allow detection) for downstream replay. They are collected into
        // TransitionalResolvedConfiguration::$deferredWarnings and drained by
        // RuntimeConfigurator after the user logger is wired up.
        $factoryResult = $this->architectureConfigurationFactory->fromArray(
            $this->getAssocArrayValue($merged, ConfigSchema::ARCHITECTURE, []),
        );

        return new TransitionalResolvedConfiguration(
            paths: $this->getListValue($merged, ConfigSchema::PATHS, ['.']),
            pathExcludes: $this->getListValue($merged, ConfigSchema::EXCLUDES, ['vendor', 'node_modules', '.git']),
            runtime: TransitionalRuntimeConfiguration::fromArray($merged),
            ruleOptions: $this->getAssocArrayValue($merged, ConfigSchema::RULES, []),
            architecture: $factoryResult->configuration,
            computedMetrics: $this->getAssocArrayValue($merged, ConfigSchema::COMPUTED_METRICS, []),
            appliedSources: $appliedSources,
            deferredWarnings: $factoryResult->warnings,
        );
    }

    /**
     * @param array<string, mixed> $merged
     * @param list<string> $default
     *
     * @return list<string>
     */
    private function getListValue(array $merged, string $key, array $default): array
    {
        if (!isset($merged[$key])) {
            return $default;
        }

        if (!\is_array($merged[$key])) {
            return $default;
        }

        $value = $merged[$key];
        // Ensure it's a list of strings
        if ($value === []) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $default
     *
     * @return array<string, mixed>
     */
    private function getAssocArrayValue(array $merged, string $key, array $default): array
    {
        if (!isset($merged[$key])) {
            return $default;
        }

        return \is_array($merged[$key]) ? $merged[$key] : $default;
    }
}
