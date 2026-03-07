<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency\Handler;

use AiMessDetector\Core\Dependency\DependencyType;
use PhpParser\Node;
use PhpParser\Node\Name;

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
        if ($type instanceof Name) {
            $resolved = $context->getResolver()->resolve($type);
            if (!self::isBuiltinType($resolved)) {
                $context->addDependency($resolved, $dependencyType, $type->getStartLine());
            }

            return;
        }

        if ($type instanceof Node\NullableType) {
            self::processType($type->type, $dependencyType, $context);

            return;
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $subType) {
                self::processType($subType, DependencyType::UnionType, $context);
            }

            return;
        }

        if ($type instanceof Node\IntersectionType) {
            foreach ($type->types as $subType) {
                self::processType($subType, DependencyType::IntersectionType, $context);
            }
        }
    }

    /**
     * @param array<Node\AttributeGroup> $attrGroups
     */
    public static function processAttributes(array $attrGroups, int $fallbackLine, DependencyContext $context): void
    {
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $context->addDependency(
                    $context->getResolver()->resolve($attr->name),
                    DependencyType::Attribute,
                    $attr->getStartLine() ?: $fallbackLine,
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
}
