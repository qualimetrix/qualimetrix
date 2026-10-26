<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Allow;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyRelationTokens;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Policy's entry to the {@code relations:} vocabulary: words the refusal of an
 * unknown token and forwards parsing to {@see DependencyRelationTokens}, which
 * owns the vocabulary.
 */
final class AllowAliasExpander
{
    /**
     * @return non-empty-list<DependencyType>|null null when the token is unknown
     */
    public static function expand(string $token): ?array
    {
        return DependencyRelationTokens::expand($token);
    }

    /** @return list<string> */
    public static function acceptedTokens(): array
    {
        return DependencyRelationTokens::acceptedTokens();
    }

    /** The sentence refusing `$token`, naming both vocabularies in full. */
    public static function unknownTokenMessage(string $context, string $token): string
    {
        $shapeAdvice = \in_array($token, ['union_type', 'intersection_type'], true)
            ? \sprintf(
                " '%s' names a type shape, not a relation kind; use 'type_reference' or a concrete type position; shape is graph metadata.",
                $token,
            )
            : '';

        return \sprintf(
            "%s.relations: unknown relation kind '%s'.%s Known direct values: %s. Known aliases: %s.",
            $context,
            $token,
            $shapeAdvice,
            self::renderDirectValues(),
            self::renderAliases(),
        );
    }

    /**
     * Renders the full list of {@see DependencyType} cases as a quoted CSV.
     * Drawn dynamically from {@see DependencyType::cases()} so the message stays
     * accurate as the enum grows.
     */
    private static function renderDirectValues(): string
    {
        $values = array_map(
            static fn(DependencyType $type): string => "'{$type->value}'",
            DependencyType::cases(),
        );
        sort($values);

        return implode(', ', $values);
    }

    /**
     * Renders the alias vocabulary as a quoted CSV.
     */
    private static function renderAliases(): string
    {
        $aliases = array_map(
            static fn(string $alias): string => "'{$alias}'",
            array_keys(DependencyRelationTokens::ALIASES),
        );
        sort($aliases);

        return implode(', ', $aliases);
    }
}
