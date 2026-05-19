<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Discovery;

use Qualimetrix\Core\Path\AbsolutePath;
use SplFileInfo;

interface FileDiscoveryInterface
{
    /**
     * Discovers PHP files in given paths.
     *
     * Yields each file at most once even when inputs overlap (e.g. `src/` + `src/sub/`,
     * or a single-file argument that also matches a directory's recursive scan).
     *
     * The iterator key is an {@see AbsolutePath} object — invalid as a PHP array key.
     * Consumers materializing the result must use `iterator_to_array($iter, false)`
     * (preserve_keys = false); the default `true` triggers a TypeError on object keys.
     *
     * @param AbsolutePath|list<AbsolutePath> $paths
     *
     * @return iterable<AbsolutePath, SplFileInfo>
     */
    public function discover(AbsolutePath|array $paths): iterable;
}
