<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Ast;

use LogicException;
use PhpParser\Node\Name;

final class ResolvedName
{
    public static function className(Name $name): ?string
    {
        if (\in_array($name->toLowerString(), ['self', 'static', 'parent'], true)) {
            return null;
        }

        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }

        if ($name->getAttribute('namespacedName') instanceof Name) {
            throw new LogicException('Not a class-name position: an unqualified function or constant name resolves at run time');
        }

        throw new LogicException('No resolvedName: a namespace or use-item name, or NameResolution::resolve() did not run over this AST');
    }

    public static function sameClass(string $left, string $right): bool
    {
        return strtolower($left) === strtolower($right);
    }
}
