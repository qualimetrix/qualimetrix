<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypes;
use Qualimetrix\Core\Ast\ResolvedName;
use Qualimetrix\Core\Symbol\ClassType;

/** Projects one resolved installed class-like declaration onto external-supertype facts. */
final class DeclaredClassLikeFactExtractor
{
    public function facts(Stmt\ClassLike $declaration): ExternalSupertypes
    {
        $spelling = $declaration->namespacedName?->toString()
            ?? throw new LogicException('A named declaration must carry its resolved name');
        $parent = $declaration instanceof Stmt\Class_ && $declaration->extends !== null
            ? ResolvedName::className($declaration->extends)
            : null;
        $interfaces = match (true) {
            $declaration instanceof Stmt\Class_ => self::resolvedNames($declaration->implements),
            $declaration instanceof Stmt\Interface_ => self::resolvedNames($declaration->extends),
            $declaration instanceof Stmt\Enum_ => [
                ...self::resolvedNames($declaration->implements),
                'UnitEnum',
                ...($declaration->scalarType !== null ? ['BackedEnum'] : []),
            ],
            default => [],
        };
        [$traits, $aliasesToString] = self::traitFacts($declaration);

        return new ExternalSupertypes(
            true,
            $spelling,
            self::classType($declaration),
            $parent,
            array_values(array_unique($interfaces)),
            array_values(array_unique($traits)),
            self::declaresToString($declaration),
            $aliasesToString,
            null,
        );
    }

    /** @return array{list<string>, bool} */
    private static function traitFacts(Stmt\ClassLike $declaration): array
    {
        $traits = [];
        $aliasesToString = false;
        foreach ($declaration->stmts as $statement) {
            if (!$statement instanceof Stmt\TraitUse) {
                continue;
            }
            array_push($traits, ...self::resolvedNames($statement->traits));
            foreach ($statement->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias
                    && $adaptation->newName !== null
                    && strcasecmp($adaptation->newName->toString(), '__toString') === 0
                ) {
                    $aliasesToString = true;
                }
            }
        }

        return [$traits, $aliasesToString];
    }

    private static function declaresToString(Stmt\ClassLike $declaration): bool
    {
        foreach ($declaration->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), '__toString') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, Node\Name> $names
     *
     * @return list<string>
     */
    private static function resolvedNames(array $names): array
    {
        $resolved = [];
        foreach ($names as $name) {
            $fqcn = ResolvedName::className($name);
            if ($fqcn === null) {
                throw new LogicException('A class-name position did not resolve to a class');
            }
            $resolved[] = $fqcn;
        }

        return $resolved;
    }

    private static function classType(Stmt\ClassLike $declaration): ClassType
    {
        return match (true) {
            $declaration instanceof Stmt\Class_ => ClassType::Class_,
            $declaration instanceof Stmt\Interface_ => ClassType::Interface_,
            $declaration instanceof Stmt\Trait_ => ClassType::Trait_,
            $declaration instanceof Stmt\Enum_ => ClassType::Enum_,
            default => throw new LogicException('Unsupported class-like declaration'),
        };
    }
}
