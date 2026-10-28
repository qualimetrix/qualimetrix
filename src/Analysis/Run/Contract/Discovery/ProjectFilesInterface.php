<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use SplFileInfo;

interface ProjectFilesInterface
{
    public function discover(RunConfiguration $run): DiscoveredProjectFiles;

    /**
     * The files the walk of {@see discover()} accepts — eligible, excluded as
     * generated, or a second name of either — without reading any of them.
     *
     * @return list<SplFileInfo>
     */
    public function select(RunConfiguration $run): array;
}
