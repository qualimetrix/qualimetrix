<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\GitScope;

/**
 * Configuration for git scope filtering of violations.
 *
 * The scope is required: this object exists only for a run that narrows its
 * report, and a nullable scope meant every consumer re-checked a condition
 * its own construction had already decided.
 */
final readonly class GitScopeFilterConfig
{
    public function __construct(
        public GitClient $gitClient,
        public GitScope $reportScope,
        public bool $strictMode,
        public AbsolutePath $projectRoot,
    ) {}
}
