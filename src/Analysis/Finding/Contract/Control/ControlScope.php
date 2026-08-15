<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Control;

/**
 * Declaration-ranked control scopes. Physical file and next-line controls are
 * represented by SuppressionType and deliberately do not participate here.
 */
enum ControlScope
{
    case Hook;
    case Property;
    case Callable;
    case Class_;

    public function specificity(): int
    {
        return match ($this) {
            self::Hook => 4,
            self::Property => 3,
            self::Callable => 2,
            self::Class_ => 1,
        };
    }
}
