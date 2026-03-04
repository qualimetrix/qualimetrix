<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline;

use AiMessDetector\Configuration\Pipeline\Stage\ConfigurationStageInterface;

interface ConfigurationPipelineInterface
{
    /**
     * Resolves the full configuration through all stages.
     */
    public function resolve(ConfigurationContext $context): ResolvedConfiguration;

    /**
     * Adds a stage to the pipeline (for plugins).
     */
    public function addStage(ConfigurationStageInterface $stage): void;

    /**
     * Returns all registered stages (sorted by priority).
     *
     * @return list<ConfigurationStageInterface>
     */
    public function stages(): array;
}
