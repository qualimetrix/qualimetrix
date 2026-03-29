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
     * @param list<string>|null $scopeFilePaths Relative paths of in-scope files for violation filtering (null = all files in scope)
     */
    public function __construct(
        public array $paths,
        public FileDiscoveryInterface $fileDiscovery,
        public ?GitClient $gitClient,
        public ?GitScope $analyzeScope,
        public ?GitScope $reportScope,
        public ?array $scopeFilePaths = null,
    ) {}
}
