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
     * generated, skipped for an unreadable header, or a second name of any —
     * without reading any of them. It refuses as that walk does.
     *
     * @return list<SplFileInfo>
     */
    public function select(RunConfiguration $run): array;
}
