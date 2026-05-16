<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

interface GitRepositoryLocatorInterface
{
    /**
     * Finds the .git directory for the current repository.
     *
     * @param string|null $workingDir Working directory to start from (defaults to getcwd())
     *
     * @return string|null Absolute path to .git directory, or null if not in a git repo
     */
    public function findGitDir(?string $workingDir = null): ?string;
}
