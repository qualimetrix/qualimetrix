<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\OutputFormat;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\ResolvedFindingExclusions;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;

/**
 * Configuration resolution pipeline.
 *
 * Collects configuration from multiple stages (defaults, composer, config file, cli)
 * and merges them according to priority order.
 *
 * Capability-specific configuration remains an ordered normalized document
 * until its owning capability explicitly consumes it.
 */
final class ConfigurationPipeline implements ConfigurationPipelineInterface
{
    /** @var list<ConfigurationStageInterface> */
    private array $stages = [];

    public function __construct() {}

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
        $documents = [];
        foreach ($stages as $stage) {
            $layer = $stage->apply($context);
            if ($layer !== null) {
                $appliedSources[] = $layer->source;
                $merged = ConfigurationMerger::merge($merged, $layer->values);
                $documents = [...$documents, ...($layer->documents === [] ? [$layer->values] : $layer->documents)];
            }
        }

        return $this->buildResolved($merged, $appliedSources, new ConfigurationDocument($documents));
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
    private function buildResolved(array $merged, array $appliedSources, ConfigurationDocument $document): TransitionalResolvedConfiguration
    {
        // Capability configuration remains in the neutral document boundary.
        // RuntimeConfigurator consumes it only after the user logger is ready,
        // so capability warnings can be delivered immediately by their owner.
        return new TransitionalResolvedConfiguration(
            paths: $this->getListValue($merged, ConfigSchema::PATHS, ['.']),
            pathExcludes: $this->getListValue($merged, ConfigSchema::EXCLUDES, ['vendor', 'node_modules', '.git']),
            runtime: TransitionalRuntimeConfiguration::fromArray($merged),
            ruleOptions: $this->getAssocArrayValue($merged, ConfigSchema::RULES, []),
            document: $document,
            ruleSelection: new RuleSelection(
                $this->getListValue($merged, ConfigSchema::ONLY_RULES, []),
                $this->getListValue($merged, ConfigSchema::DISABLED_RULES, []),
            ),
            outputFormat: new OutputFormat($this->getStringValue($merged, ConfigSchema::FORMAT, OutputFormat::DEFAULT)),
            findingExclusions: new ResolvedFindingExclusions(
                excludePaths: $this->getListValue($merged, ConfigSchema::EXCLUDE_PATHS, []),
                excludeNamespaces: $this->getListValue($merged, ConfigSchema::EXCLUDE_NAMESPACES, []),
            ),
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

    /** @param array<string, mixed> $merged */
    private function getStringValue(array $merged, string $key, string $default): string
    {
        $value = $merged[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf('Invalid value for "%s": expected string, got %s', $key, get_debug_type($value)));
        }

        return $value;
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
