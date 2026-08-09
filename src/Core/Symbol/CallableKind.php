<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

/**
 * The supported source-level callable categories.
 */
enum CallableKind: string
{
    case Method = 'method';
    case Function = 'function';
    case PropertyHook = 'property-hook';
    case AnonymousCallable = 'anonymous-callable';
}
