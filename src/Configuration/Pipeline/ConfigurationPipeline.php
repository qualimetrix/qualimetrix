<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\PathsConfiguration;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigurationStageInterface;

/**
 * Configuration resolution pipeline.
 *
 * Collects configuration from multiple stages (defaults, composer, config file, cli)
 * and merges them according to priority order.
 */
final class ConfigurationPipeline implements ConfigurationPipelineInterface
{
    /**
     * Keys whose values should be merged (union) across stages rather than replaced.
     */
    private const array MERGEABLE_LIST_KEYS = ['disabled_rules', 'exclude_paths', 'excludes', 'exclude_health'];

    /** @var list<ConfigurationStageInterface> */
    private array $stages = [];

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
                // Merge list-type keys that accumulate across stages (union semantics),
                // override everything else
                foreach ($layer->values as $key => $value) {
                    if (\is_array($value) && isset($merged[$key]) && \is_array($merged[$key])
                        && \in_array($key, self::MERGEABLE_LIST_KEYS, true)
                    ) {
                        $merged[$key] = array_values(array_unique(array_merge($merged[$key], $value)));
                    } else {
                        $merged[$key] = $value;
                    }
                }
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
        return new ResolvedConfiguration(
            paths: new PathsConfiguration(
                paths: $this->getListValue($merged, 'paths', ['.']),
                excludes: $this->getListValue($merged, 'excludes', ['vendor', 'node_modules', '.git']),
            ),
            analysis: AnalysisConfiguration::fromArray($merged),
            ruleOptions: $this->getAssocArrayValue($merged, 'rules', []),
            computedMetrics: $this->getAssocArrayValue($merged, 'computed_metrics', []),
            appliedSources: $appliedSources,
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
