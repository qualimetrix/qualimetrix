<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

/**
 * The vocabulary users write to name dependency kinds: every
 * {@see DependencyType} value, plus four aliases that group related kinds.
 *
 * Direct values are matched reflectively through {@see DependencyType::tryFrom()},
 * so a new enum case is accepted without touching this class. `attribute` and
 * `attribute_argument` are deliberately in no alias: they are metadata
 * categories of their own.
 */
final class DependencyRelationTokens
{
    /** @var array<string, non-empty-list<DependencyType>> */
    public const array ALIASES = [
        'inheritance' => [
            DependencyType::Extends,
            DependencyType::Implements,
            DependencyType::TraitUse,
        ],
        'static_access' => [
            DependencyType::StaticCall,
            DependencyType::StaticPropertyFetch,
            DependencyType::ClassConstFetch,
        ],
        'type_reference' => [
            DependencyType::TypeHint,
            DependencyType::PropertyType,
            DependencyType::ConstantType,
        ],
        'runtime_check' => [
            DependencyType::Catch_,
            DependencyType::Instanceof_,
        ],
    ];

    /**
     * The kinds one token stands for: the members of an alias, or the one
     * direct value it names; null when it is neither.
     *
     * @return non-empty-list<DependencyType>|null
     */
    public static function expand(string $token): ?array
    {
        if (isset(self::ALIASES[$token])) {
            return self::ALIASES[$token];
        }

        $direct = DependencyType::tryFrom($token);

        return $direct === null ? null : [$direct];
    }

    /**
     * Every token {@see expand()} accepts, sorted.
     *
     * @return list<string>
     */
    public static function acceptedTokens(): array
    {
        $accepted = [
            ...array_map(static fn(DependencyType $type): string => $type->value, DependencyType::cases()),
            ...array_keys(self::ALIASES),
        ];
        sort($accepted);

        return $accepted;
    }
}
