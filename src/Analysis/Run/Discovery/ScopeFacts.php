<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/** Observable file-selection facts; no source file content is read here. */
final readonly class ScopeFacts
{
    /**
     * @param list<RelativePath> $missingByPaths Regular PHP entries outside selection
     * @param list<RelativePath> $unlistableOutside
     * @param list<RelativePath> $hiddenOutsideDirectories
     */
    public function __construct(
        public array $missingByPaths,
        public array $unlistableOutside,
        public array $hiddenOutsideDirectories,
        public bool $namedFilesOnly,
        public int $generatedExcluded = 0,
        public ?AbsolutePath $unlistableOutsideRoot = null,
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
