<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Core\Path\AbsolutePath;

/** Captured source evidence shared unchanged by every narrowed selection. */
final readonly class ProjectScopeUniverse
{
    /**
     * @param list<array{target: string, path: AbsolutePath}> $denominator
     * @param list<array{target: string, directory: string}> $prunedTargets
     * @param list<ProjectScopeReason> $reasons
     * @param list<array{written: AbsolutePath, path: AbsolutePath}> $pathResolutions
     */
    public function __construct(
        public AbsolutePath $projectRoot,
        public bool $pathsAuthored,
        public array $denominator,
        public array $prunedTargets,
        public array $reasons,
        public bool $namespaceMapUsable,
        public array $pathResolutions,
    ) {}

    /**
     * @param list<AbsolutePath> $paths
     *
     * @return list<string>
     */
    public function uncoveredBy(array $paths): array
    {
        $resolved = array_map($this->resolveCaptured(...), $paths);
        if ($this->containsResolvedRoot($resolved)) {
            return [];
        }

        $uncovered = [];
        foreach ($this->denominator as $target) {
            if (!$this->containsTarget($resolved, $target['path'])) {
                $uncovered[] = $target['target'];
            }
        }

        return $uncovered;
    }

    /** @param list<AbsolutePath> $paths */
    public function containsProjectRoot(array $paths): bool
    {
        return $this->containsResolvedRoot(array_map($this->resolveCaptured(...), $paths));
    }

    private function resolveCaptured(AbsolutePath $path): AbsolutePath
    {
        foreach ($this->pathResolutions as $resolution) {
            if ($path->equals($resolution['written'])) {
                return $resolution['path'];
            }
            $relative = $path->tryRelativizeTo($resolution['written']);
            if ($relative !== null) {
                return $resolution['path']->joinRelative($relative);
            }
        }

        return $path;
    }

    /** @param list<AbsolutePath> $paths */
    private function containsResolvedRoot(array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path->equals($this->projectRoot)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<AbsolutePath> $paths */
    private function containsTarget(array $paths, AbsolutePath $target): bool
    {
        foreach ($paths as $path) {
            if ($target->equals($path) || $target->tryRelativizeTo($path) !== null) {
                return true;
            }
        }

        return false;
    }
}
