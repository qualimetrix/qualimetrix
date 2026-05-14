<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Architecture\Validation;

use Qualimetrix\Configuration\Architecture\Allow\AllowAliasExpander;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Dependency\DependencyType;

/**
 * Parses the long-form allow-target map ({@code [target: ..., relations:
 * [...], allow_cross_instance: bool]}) into the structured triple consumed by
 * {@see AllowValidator}.
 *
 * Extracted out of {@see AllowValidator} so that the validator stays the
 * thin orchestrator over short- and long-form discrimination + cross-validation
 * — this helper owns the long-form vocabulary (whitelist of keys, per-key
 * shape rules) so a future key can be added in one place.
 *
 * Static + stateless to mirror the rest of the configuration validator
 * surface ({@see LayerCriterionNormalizer}, {@see ExcludeBlockValidator},
 * {@see AllowAliasExpander}).
 */
final class LongFormAllowEntryNormalizer
{
    private const string CONFIG_PATH = 'architecture';

    /**
     * Long-form allow target keys. Any other key is rejected here as
     * "unknown long-form key" so a user-side typo cannot silently widen the
     * policy (e.g. {@code relatons:} would otherwise allow every relation kind
     * instead of the user's intended subset).
     */
    private const array ALLOWED_KEYS = ['target', 'relations', 'allow_cross_instance'];

    /**
     * Returns the parsed (targetRaw, allowCrossInstance, relations) triple for
     * a long-form entry. Caller is responsible for parsing {@code targetRaw}
     * into a {@see \Qualimetrix\Core\Architecture\Allow\LayerSelector} (the
     * normalizer is intentionally selector-agnostic so it can live in
     * Configuration without dragging in the Core selector parser).
     *
     * @param array<array-key, mixed> $entry The long-form map.
     *
     * @throws ConfigLoadException When an unsupported key is present, the
     *                             target field is missing/empty, or the
     *                             per-key shape is violated.
     *
     * @return array{0: string, 1: bool, 2: list<DependencyType>|null}
     */
    public static function normalize(string $source, int $index, array $entry): array
    {
        self::rejectUnsupportedKeys($source, $index, $entry);

        if (!isset($entry['target']) || !\is_string($entry['target']) || $entry['target'] === '') {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.allow.%s[%d]: long-form entry must include a non-empty 'target' key.",
                    $source,
                    $index,
                ),
            );
        }

        return [
            $entry['target'],
            self::parseAllowCrossInstanceFlag($source, $index, $entry),
            self::parseRelations($source, $index, $entry),
        ];
    }

    /**
     * Thin delegate to {@see AllowAliasExpander::parseList()}. The expander
     * owns the {@code relations:} shape contract (absent / non-list / empty)
     * AND the token-expansion vocabulary.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return list<DependencyType>|null
     */
    private static function parseRelations(string $source, int $index, array $entry): ?array
    {
        if (!\array_key_exists('relations', $entry)) {
            return null;
        }

        return AllowAliasExpander::parseList(
            $entry['relations'],
            \sprintf('architecture.allow.%s[%d]', $source, $index),
        );
    }

    /**
     * Extracts the {@code allow_cross_instance} long-form flag. Absent → false.
     * Non-boolean values are rejected so a user typo (e.g.
     * {@code allow_cross_instance: 'yes'}) cannot silently fall through to the
     * "false" default and surprise the user with mutual-allow warnings they
     * thought they had silenced.
     *
     * @param array<array-key, mixed> $entry
     */
    private static function parseAllowCrossInstanceFlag(string $source, int $index, array $entry): bool
    {
        if (!\array_key_exists('allow_cross_instance', $entry)) {
            return false;
        }

        $value = $entry['allow_cross_instance'];
        if (!\is_bool($value)) {
            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.allow.%s[%d]: 'allow_cross_instance' must be a boolean, got %s.",
                    $source,
                    $index,
                    get_debug_type($value),
                ),
            );
        }

        return $value;
    }

    /**
     * Closes the silent-widening loophole in the long-form allow entry. Any
     * key that is not in the {@see ALLOWED_KEYS} whitelist gets rejected with
     * a user-actionable error.
     *
     * @param array<array-key, mixed> $entry
     */
    private static function rejectUnsupportedKeys(string $source, int $index, array $entry): void
    {
        foreach (array_keys($entry) as $key) {
            if (\in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            throw new ConfigLoadException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.allow.%s[%d]: unknown long-form key '%s'. Allowed keys: %s.",
                    $source,
                    $index,
                    (string) $key,
                    implode(', ', array_map(static fn(string $k): string => "'" . $k . "'", self::ALLOWED_KEYS)),
                ),
            );
        }
    }
}
