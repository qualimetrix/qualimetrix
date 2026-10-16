<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

/**
 * The supported source-level callable categories.
 */
enum CallableKind: string
{
    case Method = 'method';
    case Function = 'function';
    case PropertyHook = 'property-hook';
    case AnonymousCallable = 'anonymous-callable';

    public function assertClassAggregationOwner(?DeclarationPath $owner, bool $isAnonymousClassContext): void
    {
        if ($owner !== null && $owner->logical->getType() !== SymbolType::Class_) {
            throw new InvalidArgumentException('Callable class aggregation owner must identify a class declaration');
        }

        $namedMember = \in_array($this, [self::Method, self::PropertyHook], true) && !$isAnonymousClassContext;
        if ($namedMember !== ($owner !== null)) {
            throw new InvalidArgumentException('Only named-class methods and property hooks require a class aggregation owner');
        }
    }
}
