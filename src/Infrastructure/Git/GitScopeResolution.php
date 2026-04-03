<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;

/**
 * Result of resolving git scope from CLI input.
 *
 * Contains all information needed for analysis: paths, file discovery strategy,
 * optional git client and scope references.
 */
final readonly class GitScopeResolution
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public array $paths,
        public FileDiscoveryInterface $fileDiscovery,
        public ?GitClient $gitClient,
        public ?GitScope $reportScope,
    ) {}
}
