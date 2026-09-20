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

    /**
     * Finds the directory git runs hooks out of.
     *
     * Not `findGitDir() . '/hooks'`: that expression is wrong in two ordinary
     * setups. `core.hooksPath` moves the directory outright — every hook
     * manager sets it, and so does this repository — and a linked worktree's
     * git dir has no `hooks/` of its own at all. Both were measured to end in
     * a hook installed where git never looks.
     *
     * @param AbsolutePath|null $workingDir working directory to start from (defaults to getcwd())
     *
     * @return AbsolutePath|null the directory, existing or not, or null when
     *                           this is not a git repository
     */
    public function findHooksDir(?AbsolutePath $workingDir = null): ?AbsolutePath;
}
