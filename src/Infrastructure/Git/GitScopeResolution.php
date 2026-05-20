<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * Result of resolving git scope from CLI input.
 *
 * Contains all information needed for analysis: paths, file discovery strategy,
 * optional git client, scope references and the explicit project root.
 *
 * The explicit {@see $projectRoot} replaces the previous indirection through
 * {@see GitClient}, where a `getProjectRoot()` accessor invited the same VO
 * to be re-extracted from a downstream service and silently mismatched with
 * a different injected value (Phase 3 deferred contract collapse).
 */
final readonly class GitScopeResolution
{
    /**
     * @param list<AbsolutePath> $paths
     */
    public function __construct(
        public array $paths,
        public FileDiscoveryInterface $fileDiscovery,
        public ?GitClient $gitClient,
        public ?GitScope $reportScope,
        public AbsolutePath $projectRoot,
    ) {}
}
