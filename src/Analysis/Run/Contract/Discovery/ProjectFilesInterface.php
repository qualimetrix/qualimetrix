<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;

interface ProjectFilesInterface
{
    public function discover(RunConfiguration $run): DiscoveredProjectFiles;
}
