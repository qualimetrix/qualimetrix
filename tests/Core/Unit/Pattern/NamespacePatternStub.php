<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\Pattern;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

final class NamespacePatternStub
{
    public static function exact(string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition(SelectorKind::Exact, $value));
    }

    public static function subtree(string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition(SelectorKind::Subtree, $value));
    }

    public static function regex(string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition(SelectorKind::Regex, $value));
    }
}
