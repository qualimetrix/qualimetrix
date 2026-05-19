<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Qualimetrix\Core\Path\AbsolutePath;

interface GitRepositoryLocatorInterface
{
    /**
     * Finds the .git directory for the current repository.
     *
     * @param AbsolutePath|null $workingDir Working directory to start from (defaults to getcwd())
     *
     * @return AbsolutePath|null Absolute path to .git directory, or null if not in a git repo
     */
    public function findGitDir(?AbsolutePath $workingDir = null): ?AbsolutePath;
}
