<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Dependency;

/**
 * Enumerates all possible types of dependencies between classes/types.
 *
 * Each dependency type represents a specific way one class can reference another.
 * This classification is used for accurate coupling analysis.
 */
enum DependencyType: string
{
    /**
     * Class extends another class.
     * Example: class Foo extends Bar {}
     */
    case Extends = 'extends';

    /**
     * Class implements an interface.
     * Example: class Foo implements BarInterface {}
     */
    case Implements = 'implements';

    /**
     * Trait use inside a class.
     * Example: class Foo { use BarTrait; }
     */
    case TraitUse = 'trait_use';

    /**
     * Object instantiation.
     * Example: new Foo()
     */
    case New_ = 'new';

    /**
     * Static method call.
     * Example: Foo::bar()
     */
    case StaticCall = 'static_call';

    /**
     * Static property access.
     * Example: Foo::$bar
     */
    case StaticPropertyFetch = 'static_property_fetch';

    /**
     * Class constant access.
     * Example: Foo::BAR
     */
    case ClassConstFetch = 'class_const_fetch';

    /**
     * Type hint in parameter, return type, or property.
     * Example: function foo(Bar $bar): Baz {}
     */
    case TypeHint = 'type_hint';

    /**
     * Catch block exception type.
     * Example: catch (FooException $e) {}
     */
    case Catch_ = 'catch';

    /**
     * Instanceof check.
     * Example: $x instanceof Foo
     */
    case Instanceof_ = 'instanceof';

    /**
     * PHP 8 Attribute usage.
     * Example: #[Foo]
     */
    case Attribute = 'attribute';

    /**
     * Typed property declaration.
     * Example: private Foo $bar;
     */
    case PropertyType = 'property_type';

    /**
     * Intersection type (PHP 8.1+).
     * Example: function foo(Foo&Bar $x) {}
     */
    case IntersectionType = 'intersection_type';

    /**
     * Union type (PHP 8.0+).
     * Example: function foo(Foo|Bar $x) {}
     */
    case UnionType = 'union_type';

    /**
     * Returns human-readable description of the dependency type.
     */
    public function description(): string
    {
        return match ($this) {
            self::Extends => 'extends class',
            self::Implements => 'implements interface',
            self::TraitUse => 'uses trait',
            self::New_ => 'instantiates',
            self::StaticCall => 'calls static method',
            self::StaticPropertyFetch => 'accesses static property',
            self::ClassConstFetch => 'accesses class constant',
            self::TypeHint => 'uses as type hint',
            self::Catch_ => 'catches exception',
            self::Instanceof_ => 'checks instanceof',
            self::Attribute => 'uses attribute',
            self::PropertyType => 'uses as property type',
            self::IntersectionType => 'uses in intersection type',
            self::UnionType => 'uses in union type',
        };
    }

    /**
     * Returns true if this dependency type creates a strong coupling.
     *
     * Strong coupling includes inheritance and trait use, which are
     * harder to change than other dependency types.
     */
    public function isStrongCoupling(): bool
    {
        return match ($this) {
            self::Extends, self::Implements, self::TraitUse => true,
            default => false,
        };
    }
}
