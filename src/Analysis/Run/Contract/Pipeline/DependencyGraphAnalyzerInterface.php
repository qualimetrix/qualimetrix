<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;

/** Runs the complete discovery-to-dependency-graph analysis lifecycle. */
interface DependencyGraphAnalyzerInterface
{
    public function analyze(RunConfiguration $configuration, FileDiscoveryInterface $fileDiscovery): DependencyGraphAnalysisResult;
}
