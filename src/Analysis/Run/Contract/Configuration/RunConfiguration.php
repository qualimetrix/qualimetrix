<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Core\Path\AbsolutePath;

/** Immutable execution input owned by Run. */
final readonly class RunConfiguration
{
    /**
     * @param list<AbsolutePath> $paths
     * @param list<string> $pathExcludes
     */
    public function __construct(
        public array $paths,
        public array $pathExcludes,
        public AbsolutePath $projectRoot,
        public GeneratedFilePolicy $generatedFilePolicy,
    ) {}
}
