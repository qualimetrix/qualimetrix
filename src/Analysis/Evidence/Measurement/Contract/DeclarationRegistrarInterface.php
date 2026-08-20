<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use PhpParser\NodeVisitor;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/**
 * Traversal participant that fills the declaration index of one file.
 *
 * It is added by the traversal owner and registers every class-like and every
 * callable it sees, so the numbering does not depend on which collectors are
 * enabled, which rules are on, or whether the run is parallel.
 */
interface DeclarationRegistrarInterface extends NodeVisitor
{
    public function index(): FileDeclarationIndex;
}
