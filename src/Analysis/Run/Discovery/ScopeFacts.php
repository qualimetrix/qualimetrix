<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Observable file-selection facts, and the namespaces of the code selection
 * removed from the run.
 *
 * @qmx-threshold health.cohesion 40.0 -- Immutable observations expose independent selection and denominator claims captured during the same run lifecycle; the finite boundary keeps a further cohesion loss visible.
 */
final readonly class ScopeFacts
{
    /**
     * @param list<RelativePath> $missingByPaths Regular PHP entries outside selection
     * @param list<RelativePath> $unlistableOutside
     * @param list<RelativePath> $hiddenOutsideDirectories
     * @param RemovedCodeNamespaces|null $removedCode null when they were not surveyed, which counts as unknown
     */
    public function __construct(
        public array $missingByPaths,
        public array $unlistableOutside,
        public array $hiddenOutsideDirectories,
        public bool $namedFilesOnly,
        public int $generatedExcluded = 0,
        public ?AbsolutePath $unlistableOutsideRoot = null,
        public ?RemovedCodeNamespaces $removedCode = null,
    ) {}

    public function pathsNarrowed(): bool
    {
        return $this->missingByPaths !== [];
    }

    public function denominatorUnknown(): bool
    {
        return $this->unlistableOutside !== [] || $this->hiddenOutsideDirectories !== [] || $this->unlistableOutsideRoot !== null;
    }
}
