<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use PhpParser\NodeVisitor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

interface DependencyTraversalParticipantInterface extends NodeVisitor
{
    /**
     * The index belongs to the traversal that is about to run, so a participant
     * shared between the check and the graph path always numbers against the
     * path it is currently taking part in.
     */
    public function beginFile(RelativePath $file, FileDeclarationIndex $index): void;

    /** @return list<Dependency> */
    public function dependencies(): array;
}
