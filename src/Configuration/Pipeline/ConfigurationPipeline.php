<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\PathsConfiguration;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigurationStageInterface;

/**
 * Configuration resolution pipeline.
 *
 * Collects configuration from multiple stages (defaults, composer, config file, cli)
 * and merges them according to priority order.
 *
 * Optional logger is forwarded to {@see ArchitectureConfigurationFactory} so that
 * mutual-allow warnings and pattern-collision warnings surface to the user. In
 * production this is a {@see \Qualimetrix\Infrastructure\Logging\DelegatingLogger}
 * pointing at {@see \Qualimetrix\Infrastructure\Logging\LoggerHolder}; warnings
 * emitted during {@see resolve()} are forwarded to whichever logger the holder
 * carries at log time (NullLogger before CLI configuration, console/file logger
 * afterwards). The factory itself accepts an optional logger and falls back to
 * NullLogger if none is supplied.
 */
final class ConfigurationPipeline implements ConfigurationPipelineInterface
{
    /** @var list<ConfigurationStageInterface> */
    private array $stages = [];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function resolve(ConfigurationContext $context): ResolvedConfiguration
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
    private function buildResolved(array $merged, array $appliedSources): ResolvedConfiguration
    {
        // The architecture factory emits warnings for mutual-allow pairs and
        // (until Step 1 lands) also forwards them to the pipeline logger so the
        // existing surface keeps working. Step 1 will wire the result's
        // warning list through ResolvedConfiguration into RuntimeConfigurator.
        $architectureResult = (new ArchitectureConfigurationFactory())->fromArray(
            $this->getAssocArrayValue($merged, ConfigSchema::ARCHITECTURE, []),
            $this->logger,
        );

        return new ResolvedConfiguration(
            paths: new PathsConfiguration(
                paths: $this->getListValue($merged, ConfigSchema::PATHS, ['.']),
                excludes: $this->getListValue($merged, ConfigSchema::EXCLUDES, ['vendor', 'node_modules', '.git']),
            ),
            analysis: AnalysisConfiguration::fromArray($merged),
            ruleOptions: $this->getAssocArrayValue($merged, ConfigSchema::RULES, []),
            computedMetrics: $this->getAssocArrayValue($merged, ConfigSchema::COMPUTED_METRICS, []),
            appliedSources: $appliedSources,
            architecture: $architectureResult->configuration,
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
