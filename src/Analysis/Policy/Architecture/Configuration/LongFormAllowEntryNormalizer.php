<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowAliasExpander;

/**
 * Parses the long-form allow-target map ({@code [target: ..., relations:
 * [...], allow_cross_instance: bool]}) into the structured triple consumed by
 * {@see AllowValidator}.
 *
 * Extracted out of {@see AllowValidator} so that the validator stays the
 * thin orchestrator over short- and long-form discrimination + cross-validation
 * — this helper owns the long-form vocabulary (the keys, per-key shape rules)
 * so a future key can be added in one place.
 *
 * The configuration engine carries an allow target unread, because it is
 * written as a layer name or as this map, so the map's keys are recognised
 * here — by the document's one spelling rule: each key in its snake_case,
 * kebab-case or camelCase spelling, and a key that is none of them refused
 * whatever its value, `~` included.
 *
 * Static + stateless to mirror the rest of the configuration validator
 * surface ({@see LayerCriterionNormalizer}, {@see ExcludeBlockValidator},
 * {@see AllowAliasExpander}).
 */
final class LongFormAllowEntryNormalizer
{
    /**
     * Long-form allow target keys, canonical spelling. Any other key is
     * rejected so a user-side typo cannot silently widen the policy (e.g.
     * {@code relatons:} would otherwise allow every relation kind instead of
     * the user's intended subset).
     */
    private const array KEYS = ['target', 'relations', 'allow_cross_instance'];

    /**
     * Returns the parsed (targetRaw, allowCrossInstance, relations) triple for
     * a long-form entry. Caller is responsible for parsing {@code targetRaw}
     * into a {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector}.
     *
     * @throws ConfigurationRefusal When an unsupported key is present, the
     *                              target field is missing/empty, or the
     *                              per-key shape is violated.
     *
     * @return array{0: string, 1: bool, 2: list<DependencyType>|null}
     */
    public static function normalize(string $source, int $index, SectionSpot $entry): array
    {
        $keys = self::recogniseKeys($source, $index, $entry);

        $target = $entry->child($keys['target'] ?? 'target');
        $targetRaw = $target->value();
        if (!\is_string($targetRaw) || $targetRaw === '') {
            throw $target->refusal(\sprintf(
                "architecture.allow.%s[%d]: long-form entry must include a non-empty 'target' key.",
                $source,
                $index,
            ));
        }

        return [
            $targetRaw,
            self::parseAllowCrossInstanceFlag($source, $index, $entry->child($keys['allow_cross_instance'] ?? 'allow_cross_instance')),
            self::parseRelations($source, $index, $entry->child($keys['relations'] ?? 'relations')),
        ];
    }

    /**
     * Not written — absent or {@code ~} — is the documented default, "any
     * relation allowed", the same as a bare target. A written list is a
     * filter: {@see parseRelationList()} owns its shape (non-list, empty) and
     * {@see AllowAliasExpander} its vocabulary.
     *
     * @return list<DependencyType>|null
     */
    private static function parseRelations(string $source, int $index, SectionSpot $relations): ?array
    {
        if (!$relations->isWritten()) {
            return null;
        }

        return self::parseRelationList($relations, \sprintf('architecture.allow.%s[%d]', $source, $index));
    }

    /**
     * A written {@code relations:} list: a non-empty list of non-empty
     * strings, each a direct {@see DependencyType} value or an alias, expanded
     * in declaration order with later duplicates absorbed. Null when nothing
     * is written, which the caller reads as "any relation allowed".
     *
     * @param string $context the entry's path, e.g. {@code architecture.allow.app[0]}
     *
     * @throws ConfigurationRefusal through the layer that wrote the list
     *
     * @return list<DependencyType>|null
     */
    public static function parseRelationList(SectionSpot $relations, string $context): ?array
    {
        $raw = $relations->value();
        if ($raw === null) {
            return null;
        }

        if (!\is_array($raw) || !array_is_list($raw)) {
            throw $relations->refusal(\sprintf('%s.relations: must be a list of relation kinds or aliases.', $context));
        }

        if ($raw === []) {
            throw $relations->refusal(\sprintf(
                "%s.relations: must list at least one relation kind. " .
                'Use a bare target (e.g. `- target_layer` instead of `- target: target_layer`) ' .
                'to keep the "any relation allowed" semantics.',
                $context,
            ));
        }

        $expanded = [];
        foreach ($raw as $index => $token) {
            foreach (self::expandToken($relations->child($index), $token, $context, $index) as $type) {
                $expanded[$type->value] ??= $type;
            }
        }

        return array_values($expanded);
    }

    /** @return non-empty-list<DependencyType> */
    private static function expandToken(SectionSpot $spot, mixed $token, string $context, int $index): array
    {
        if (!\is_string($token) || $token === '') {
            throw $spot->refusal(\sprintf('%s.relations[%d]: each entry must be a non-empty string.', $context, $index));
        }

        return AllowAliasExpander::expand($token)
            ?? throw $spot->refusal(AllowAliasExpander::unknownTokenMessage($context, $token), AllowAliasExpander::acceptedTokens(), $token);
    }

    /**
     * Extracts the {@code allow_cross_instance} long-form flag. Not written →
     * false. Non-boolean values are rejected so a user typo (e.g.
     * {@code allow_cross_instance: 'yes'}) cannot silently fall through to the
     * "false" default and surprise the user with wildcard self-allow warnings
     * they thought they had silenced.
     */
    private static function parseAllowCrossInstanceFlag(string $source, int $index, SectionSpot $flag): bool
    {
        $value = $flag->value();
        if ($value === null) {
            return false;
        }

        if (!\is_bool($value)) {
            throw $flag->refusal(\sprintf(
                "architecture.allow.%s[%d]: '%s' must be a boolean, got %s.",
                $source,
                $index,
                $flag->path[\count($flag->path) - 1],
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Closes the silent-widening loophole in the long-form allow entry: every
     * written key — `~`-valued ones included — must be one of {@see KEYS} in
     * an accepted spelling, and each key may be written once.
     *
     * @return array<string, string> canonical key => the spelling written
     */
    private static function recogniseKeys(string $source, int $index, SectionSpot $entry): array
    {
        $recognised = [];
        foreach ($entry->keys() as $written) {
            $canonical = self::canonical($source, $index, $entry, $written);

            if (isset($recognised[$canonical])) {
                throw $entry->child($written)->refusal(\sprintf(
                    "architecture.allow.%s[%d]: '%s' and '%s' are two spellings of one key; keep one of them.",
                    $source,
                    $index,
                    $recognised[$canonical],
                    $written,
                ));
            }

            $recognised[$canonical] = $written;
        }

        return $recognised;
    }

    private static function canonical(string $source, int $index, SectionSpot $entry, string $written): string
    {
        foreach (self::KEYS as $canonical) {
            if (\in_array($written, ConfigKeySpelling::acceptedSpellings($canonical), true)) {
                return $canonical;
            }
        }

        $accepted = self::KEYS;
        sort($accepted);

        foreach (self::KEYS as $canonical) {
            if (ConfigKeySpelling::sameWords($written, $canonical)) {
                throw $entry->child($written)->refusal(
                    \sprintf(
                        "architecture.allow.%s[%d]: long-form key '%s' is not written in an accepted spelling; write '%s' (its snake_case, camelCase and kebab-case spellings are accepted).",
                        $source,
                        $index,
                        $written,
                        $canonical,
                    ),
                    $accepted,
                );
            }
        }

        throw $entry->child($written)->refusal(
            \sprintf(
                "architecture.allow.%s[%d]: unknown long-form key '%s'. Allowed keys: %s.",
                $source,
                $index,
                $written,
                implode(', ', array_map(static fn(string $key): string => "'" . $key . "'", self::KEYS)),
            ),
            $accepted,
        );
    }
}
