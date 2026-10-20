<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/**
 * Implemented by anything that asks the traversal owner for declaration numbers.
 *
 * The index arrives per file from whoever owns the traversal; an implementor
 * never creates one, because a private index would number a subset of the file
 * and agree with the other producers only by accident.
 */
interface DeclarationIndexAwareInterface
{
    public function useDeclarationIndex(FileDeclarationIndex $index): void;
}
