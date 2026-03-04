<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\PathsConfiguration;

/**
 * Fully resolved configuration after pipeline processing.
 */
final readonly class ResolvedConfiguration
{
    /**
     * @param array<string, mixed> $ruleOptions
     */
    public function __construct(
        public PathsConfiguration $paths,
        public AnalysisConfiguration $analysis,
        public array $ruleOptions,
    ) {}
}
