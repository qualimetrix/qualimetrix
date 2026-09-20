<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer\Contract;

/**
 * Point the autoload map at the tree a run is about to analyse.
 *
 * Promised to the console runtime, which is the only thing that knows a run's
 * paths before the pipeline starts. The map itself stays internal: what the
 * caller needs is the ability to aim it, not the ability to read it.
 */
interface AnalysedInstallAnchorInterface
{
    /**
     * @param list<string> $analysedPaths
     */
    public function pointAt(string $projectRoot, array $analysedPaths): void;
}
