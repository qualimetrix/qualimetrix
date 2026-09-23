<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use ReflectionClass;

/**
 * The supertypes of classes and interfaces PHP itself declares, read from the
 * running PHP.
 *
 * These end an inheritance chain on known ground: nothing about them depends
 * on what the run analysed. Only a name {@see PhpBuiltinClassRegistry} lists is
 * read, and with autoloading off, so no analysed or vendor file is ever
 * loaded. A listed name this runtime does not provide — a bundled extension it
 * does not load — or one that resolves to a user-land class here (a polyfill)
 * answers null, which the caller reports as a cut rather than a root.
 *
 * @internal Consumed by {@see ClassContextFactory}.
 */
final class PhpClassHierarchy
{
    /**
     * The parent class, as a list of zero or one names; null when the name is
     * not one PHP declares in this runtime.
     *
     * @return list<string>|null
     */
    public static function parentOf(string $fqn): ?array
    {
        $declaration = self::declaration($fqn);
        if ($declaration === null) {
            return null;
        }

        $parent = $declaration->getParentClass();

        return $parent === false ? [] : [$parent->getName()];
    }

    /**
     * Every interface the name implements or extends, transitively; null when
     * the name is not one PHP declares in this runtime.
     *
     * @return list<string>|null
     */
    public static function interfacesOf(string $fqn): ?array
    {
        return self::declaration($fqn)?->getInterfaceNames();
    }

    /**
     * The attributes PHP's own declaration of the name carries; null when the
     * name is not one PHP declares in this runtime.
     *
     * @return list<string>|null
     */
    public static function attributesOf(string $fqn): ?array
    {
        $declaration = self::declaration($fqn);

        return $declaration === null
            ? null
            : array_map(static fn($attribute): string => $attribute->getName(), $declaration->getAttributes());
    }

    /**
     * @return ReflectionClass<object>|null
     */
    private static function declaration(string $fqn): ?ReflectionClass
    {
        if (!PhpBuiltinClassRegistry::isBuiltin($fqn)) {
            return null;
        }

        if (!class_exists($fqn, false) && !interface_exists($fqn, false)) {
            return null;
        }

        $reflection = new ReflectionClass($fqn);

        return $reflection->isInternal() ? $reflection : null;
    }
}
