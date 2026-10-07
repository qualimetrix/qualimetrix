<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction;

use LogicException;
use PhpParser\Node\Name;
use Qualimetrix\Core\Ast\ResolvedName;

/**
 * Reads class names annotated by the shared PHP name-resolution pass.
 */
final readonly class DependencyResolver
{
    public function resolve(Name $name): string
    {
        return ResolvedName::className($name)
            ?? throw new LogicException('A special class name must be handled by its owning dependency position');
    }
}
