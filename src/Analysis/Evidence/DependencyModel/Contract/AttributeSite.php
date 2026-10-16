<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

enum AttributeSite: string
{
    case ClassHeader = 'class_header';
    case Method = 'method';
    case Property = 'property';
    case Parameter = 'parameter';
    case PromotedParameter = 'promoted_parameter';
    case ClassConstant = 'class_constant';
    case EnumCase = 'enum_case';
    case PropertyHook = 'property_hook';
    case HookParameter = 'hook_parameter';
    case NestedCallable = 'nested_callable';
    case NestedFunction = 'nested_function';
    case NestedClass = 'nested_class';

    public function isDeclaredMember(): bool
    {
        return \in_array($this, [
            self::Method,
            self::Property,
            self::Parameter,
            self::PromotedParameter,
            self::ClassConstant,
            self::EnumCase,
            self::PropertyHook,
            self::HookParameter,
        ], true);
    }
}
