<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;

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

    public function resolve(ConfigurationResolutionRequest $request): ConfigurationDocument
    {
        $documents = [];
        foreach ($this->stages() as $stage) {
            $layer = $stage->apply($request);
            if ($layer === null) {
                continue;
            }

            if ($layer->documents === []) {
                $documents[] = ['source' => $layer->source, 'values' => $layer->values];
                continue;
            }
            foreach ($layer->documents as $values) {
                $documents[] = ['source' => $layer->source, 'values' => $values];
            }
        }

        return new ConfigurationDocument($documents, $request->workingDirectory);
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
        $stages = [];
        foreach ($this->stages as $stage) {
            foreach ($stages as $index => $candidate) {
                if ($stage->priority() < $candidate->priority()) {
                    array_splice($stages, $index, 0, [$stage]);
                    continue 2;
                }
            }
            $stages[] = $stage;
        }

        return $stages;
    }
}
