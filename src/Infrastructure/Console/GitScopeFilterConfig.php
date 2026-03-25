<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\GitScope;

/**
 * Configuration for git scope filtering of violations.
 */
final readonly class GitScopeFilterConfig
{
    public function __construct(
        public GitClient $gitClient,
        public ?GitScope $reportScope,
        public ?GitScope $analyzeScope,
        public bool $strictMode,
    ) {}
}
