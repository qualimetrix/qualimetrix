<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Allow;

use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Dependency\DependencyType;

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
 *   accepted by `relations:` with no Phase 2 code change required.
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
 * Errors are surfaced as {@see ConfigLoadException} so the configuration
 * pipeline can prepend its own user-facing path prefix.
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
     * High-level entry point for the {@code relations:} long-form key. Accepts
     * the raw YAML value (which is {@code mixed} until validated), enforces
     * the shape contract (must be a non-empty list) and delegates to
     * {@see self::expand()} for actual expansion.
     *
     * Returns null when {@code $raw} is absent (i.e. the user did not declare
     * {@code relations:} at all) so the caller can leave
     * {@see \Qualimetrix\Core\Architecture\Allow\AllowTarget::$relations} null
     * (= "any relation allowed").
     *
     * Centralising shape validation here keeps {@see \Qualimetrix\Configuration\Architecture\Validation\AllowValidator}
     * a thin orchestrator over four single-purpose helpers — the WMC cost of
     * the four shape checks (non-array, non-list, empty, expand) lives here
     * next to the rest of the alias-expansion concern.
     *
     *
     * @throws ConfigLoadException When the shape contract is violated or a
     *                             token cannot be expanded.
     *
     * @return list<DependencyType>|null
     */
    public static function parseList(mixed $raw, string $context): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new ConfigLoadException(
                'architecture',
                \sprintf('%s.relations: must be a list of relation kinds or aliases.', $context),
            );
        }

        if ($raw === []) {
            throw new ConfigLoadException(
                'architecture',
                \sprintf(
                    "%s.relations: must list at least one relation kind. " .
                    'Use a bare target (e.g. `- target_layer` instead of `- target: target_layer`) ' .
                    'to keep the "any relation allowed" semantics.',
                    $context,
                ),
            );
        }

        return self::expand($raw, $context);
    }

    /**
     * Expands a user-written token list into a deduplicated {@see DependencyType}
     * list, preserving order of first appearance.
     *
     * @param list<string> $tokens Raw tokens as written in the YAML
     *                             {@code relations:} list (e.g.
     *                             {@code ['inheritance', 'attribute']}).
     * @param string $context User-facing config path prefix used in error messages
     *                        (e.g. {@code architecture.allow.app[0]}).
     *
     * @throws ConfigLoadException When a token is neither a direct
     *                             {@see DependencyType} value nor a known alias.
     *
     * @return list<DependencyType>
     */
    public static function expand(array $tokens, string $context): array
    {
        $expanded = [];
        $seen = [];

        foreach ($tokens as $index => $token) {
            if (!\is_string($token) || $token === '') {
                throw new ConfigLoadException(
                    'architecture',
                    \sprintf(
                        '%s.relations[%d]: each entry must be a non-empty string.',
                        $context,
                        $index,
                    ),
                );
            }

            foreach (self::resolveToken($token, $context) as $type) {
                $key = $type->value;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $expanded[] = $type;
            }
        }

        return $expanded;
    }

    /**
     * Resolves a single user token into the {@see DependencyType} values it
     * represents. Aliases are looked up in {@see self::ALIASES}; direct values
     * are looked up reflectively against {@see DependencyType::cases()}.
     *
     * @return non-empty-list<DependencyType>
     */
    private static function resolveToken(string $token, string $context): array
    {
        if (isset(self::ALIASES[$token])) {
            return self::ALIASES[$token];
        }

        $direct = DependencyType::tryFrom($token);
        if ($direct !== null) {
            return [$direct];
        }

        throw new ConfigLoadException(
            'architecture',
            \sprintf(
                "%s.relations: unknown relation kind '%s'. Known direct values: %s. Known aliases: %s.",
                $context,
                $token,
                self::renderDirectValues(),
                self::renderAliases(),
            ),
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
