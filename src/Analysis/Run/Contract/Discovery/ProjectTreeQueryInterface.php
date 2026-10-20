<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

interface ProjectTreeQueryInterface
{
    public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot;

    public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence;

    public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence;
}
