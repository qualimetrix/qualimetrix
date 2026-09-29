<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Core\Path\AbsolutePath;

/** The initial source snapshot and origin travel with every narrowed verdict. */
final readonly class ProjectScopeMeasurement
{
    /**
     * @param list<AbsolutePath> $paths
     * @param list<array{target: string, path: AbsolutePath}> $denominator
     * @param list<string> $uncoveredRoots
     * @param list<array{target: string, directory: string}> $prunedTargets
     * @param list<array{written: AbsolutePath, path: AbsolutePath}> $pathResolutions
     * @param list<ProjectScopeReason> $reasons
     */
    public function __construct(
        public AbsolutePath $projectRoot,
        public array $paths,
        public bool $pathsAuthored,
        private ProjectScopeState $scopeState,
        public array $denominator,
        public array $uncoveredRoots,
        public array $prunedTargets,
        public array $reasons,
        public bool $namespaceMapUsable,
        public array $pathResolutions,
    ) {}

    public function state(): ProjectScopeState
    {
        return $this->scopeState;
    }
}
