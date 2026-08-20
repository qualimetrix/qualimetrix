<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Analysis\Evidence\Measurement\Visitor\DeclarationRegistrarVisitor;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/** Creates the per-file registrar together with the index it fills. */
final class DeclarationRegistrarFactory
{
    public function createForFile(): DeclarationRegistrarInterface
    {
        return new DeclarationRegistrarVisitor(new FileDeclarationIndex());
    }
}
