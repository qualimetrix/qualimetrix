<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Expands a user-written list of {@code relations:} tokens into a deduplicated
 * {@see DependencyType} list.
 *
 * Two kinds of tokens are accepted:
 *
 * - **Direct values.** Matched **reflectively** against {@see DependencyType::cases()}
 *   via {@see DependencyType::tryFrom()}. This is the mechanism that closes the
 *   drift risk between the user-facing surface (`relations:` YAML) and the
 *   collector enum: a new {@see DependencyType} case automatically becomes
 *   accepted by `relations:` with no code change required.
 * - **Aliases.** Validated against the Phase-2-controlled hardcoded
 *   {@see self::ALIASES} map. Expand to their constituent direct values at
 *   config-load time. The four aliases (`inheritance`, `static_access`,
 *   `type_reference`, `runtime_check`) are convenience groupings; `attribute`
 *   intentionally stands alone as a distinct metadata category.
 *
 * The expander preserves declaration order on first occurrence and deduplicates
 * downstream duplicates so that {@code relations: [inheritance, extends]}
 * yields {@code [Extends, Implements, TraitUse]} (the trailing `extends` is
 * absorbed by the alias expansion that already includes it).
 *
 * Only the vocabulary lives here. Reading the written list and refusing it
 * through the layer that wrote it belongs to the configuration zone
 * ({@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\LongFormAllowEntryNormalizer}),
 * which this zone may not import.
 */
final class AllowAliasExpander
{
    /**
     * Phase-2-controlled alias vocabulary. Values reference {@see DependencyType}
     * cases directly so adding a new enum case stays a one-line change in
     * {@see DependencyType} — only this map needs to be touched when a new
     * alias is introduced.
     *
     * @var array<string, list<DependencyType>>
     */
    private const array ALIASES = [
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
            DependencyType::IntersectionType,
            DependencyType::UnionType,
        ],
        'runtime_check' => [
            DependencyType::Catch_,
            DependencyType::Instanceof_,
        ],
    ];

    /**
     * The {@see DependencyType} values one token stands for: the members of an
     * alias, or the one direct value it names; null when it is neither.
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
     * Every token {@see expand()} accepts, sorted — the suggestions a refusal
     * of an unknown token offers.
     *
     * @return list<string>
     */
    public static function acceptedTokens(): array
    {
        $accepted = [...self::acceptedDirectValues(), ...array_keys(self::ALIASES)];
        sort($accepted);

        return $accepted;
    }

    /** The sentence refusing `$token`, naming both vocabularies in full. */
    public static function unknownTokenMessage(string $context, string $token): string
    {
        return \sprintf(
            "%s.relations: unknown relation kind '%s'. Known direct values: %s. Known aliases: %s.",
            $context,
            $token,
            self::renderDirectValues(),
            self::renderAliases(),
        );
    }

    /** @return list<string> */
    private static function acceptedDirectValues(): array
    {
        return array_map(
            static fn(DependencyType $type): string => $type->value,
            DependencyType::cases(),
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
            array_keys(self::ALIASES),
        );
        sort($aliases);

        return implode(', ', $aliases);
    }
}
