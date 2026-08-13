<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use PhpParser\NodeVisitor;
use Qualimetrix\Core\Path\RelativePath;

interface DependencyTraversalParticipantInterface extends NodeVisitor
{
    public function beginFile(RelativePath $file): void;

    /** @return list<Dependency> */
    public function dependencies(): array;
}
