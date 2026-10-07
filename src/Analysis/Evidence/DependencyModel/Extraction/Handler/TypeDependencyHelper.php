<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\Handler;

use PhpParser\Node;
use PhpParser\Node\Name;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\TypeShape;

final class TypeDependencyHelper
{
    /**
     * @var list<string>
     */
    private const array BUILTIN_TYPES = [
        'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean',
        'array', 'object', 'callable', 'iterable', 'void', 'null', 'never',
        'mixed', 'true', 'false', 'self', 'static', 'parent',
    ];

    /**
     * @var list<string>
     */
    private const array SELF_PARENT_NAMES = ['self', 'static', 'parent'];

    public static function processType(Node $type, DependencyType $dependencyType, DependencyContext $context): void
    {
        self::processTypeWithShape($type, $dependencyType, self::shapeOf($type), $context);
    }

    private static function processTypeWithShape(
        Node $type,
        DependencyType $dependencyType,
        TypeShape $shape,
        DependencyContext $context,
    ): void {
        if ($type instanceof Name) {
            if ($type->isSpecialClassName()) {
                return;
            }

            $resolved = $context->getResolver()->resolve($type);
            $context->addTypeDependency($resolved, $dependencyType, $shape, $type->getStartLine());

            return;
        }

        if ($type instanceof Node\NullableType) {
            self::processTypeWithShape($type->type, $dependencyType, $shape, $context);

            return;
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $subType) {
                self::processTypeWithShape($subType, $dependencyType, $shape, $context);
            }

            return;
        }

        if ($type instanceof Node\IntersectionType) {
            foreach ($type->types as $subType) {
                self::processTypeWithShape($subType, $dependencyType, $shape, $context);
            }
        }
    }

    /**
     * @param array<Node\AttributeGroup> $attrGroups
     */
    public static function processAttributes(
        array $attrGroups,
        int $fallbackLine,
        AttributeSite $site,
        DependencyContext $context,
    ): void {
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $context->addAttributeDependency(
                    $context->getResolver()->resolve($attr->name),
                    $site,
                    $attr->getStartLine() !== 0 ? $attr->getStartLine() : $fallbackLine,
                );
            }
        }
    }

    public static function isBuiltinType(string $name): bool
    {
        return \in_array(strtolower($name), self::BUILTIN_TYPES, true);
    }

    public static function isSelfOrParent(string $name): bool
    {
        return \in_array(strtolower($name), self::SELF_PARENT_NAMES, true);
    }

    private static function shapeOf(Node $type): TypeShape
    {
        if ($type instanceof Node\NullableType) {
            return TypeShape::Nullable;
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $subType) {
                if ($subType instanceof Node\IntersectionType) {
                    return TypeShape::Dnf;
                }
            }

            if (\count($type->types) === 2) {
                foreach ($type->types as $subType) {
                    if ($subType instanceof Node\Identifier && strtolower($subType->toString()) === 'null') {
                        return TypeShape::Nullable;
                    }
                }
            }

            return TypeShape::Union;
        }

        if ($type instanceof Node\IntersectionType) {
            return TypeShape::Intersection;
        }

        return TypeShape::Single;
    }
}
