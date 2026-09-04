<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

/**
 * Per-section key normalization policy used by {@see YamlConfigLoader}.
 *
 * The loader's old opt-out model (removed `identifierKeySections()` /
 * `nestedIdentifierKeyPaths()` wrappers) protected only sub-array descendants;
 * scalar leaves under a MIXED root such as {@code architecture.max_expanded_layers}
 * were silently camelCased before downstream consumers could see them.
 * Three recurrences of this class of bug during Phase 2 (allow subtree,
 * {@code allow_cross_instance} long-form key, {@code max_expanded_layers}
 * scalar leaf) drove the move to an explicit per-section policy declared
 * in {@see \Qualimetrix\Analysis\Configuration\ConfigSchema::sectionPolicies()}.
 *
 * Every root key returned by
 * {@see \Qualimetrix\Analysis\Configuration\ConfigSchema::allowedRootKeys()} must have
 * an explicit policy entry; a missing entry throws {@see \LogicException}
 * (fail-fast wiring guarantee).
 *
 * See [ADR 0009](../../../docs/adr/0009-yaml-loader-normalization-model.md).
 */
enum SectionNormalizationPolicy
{
    /**
     * Section sub-keys are camelCased at every level.
     *
     * Appropriate for typed sections where keys are schema-known options
     * (e.g. {@code cache.dir} and {@code parallel.workers}). Also the implicit default the loader
     * used historically for any section not listed in the opt-out registries.
     */
    case NORMALIZE_TO_CAMEL_CASE;

    /**
     * Section's level-1 keys are preserved verbatim; level-2 and deeper
     * resume {@see self::NORMALIZE_TO_CAMEL_CASE}.
     *
     * Appropriate for sections whose immediate children are user-defined
     * identifiers (rule names, computed-metric names) but whose nested
     * option keys belong to a typed schema. Used by {@code rules} and
     * {@code computed_metrics}: the rule slug stays
     * {@code complexity.cyclomatic}; the option {@code warning_threshold}
     * inside it becomes {@code warningThreshold}.
     *
     * This is the exact semantic of the previous (now-removed)
     * {@code ConfigSchema::identifierKeySections()} list, made explicit.
     */
    case PRESERVE_IMMEDIATE_CHILDREN;

    /**
     * Section's entire descendant tree is preserved verbatim, including
     * scalar leaves at every depth.
     *
     * Closes the leaf-normalization gap the opt-out model could not address.
     * Appropriate for sections where user-defined identifiers and snake_case
     * option keys nest arbitrarily and the schema cannot enumerate every
     * key path. Used by {@code architecture} (Phase 3.5 migration): layer
     * names, long-form target keys ({@code target}, {@code relations},
     * {@code allow_cross_instance}), and the {@code max_expanded_layers}
     * scalar leaf all survive untransformed.
     *
     * Stricter than {@see self::PRESERVE_IMMEDIATE_CHILDREN} — chosen as a
     * separate case rather than a depth=∞ degenerate of it so that
     * collapsing/expanding the depth boundary cannot happen silently for
     * existing users of {@code PRESERVE_IMMEDIATE_CHILDREN}.
     */
    case PRESERVE_SUBTREE;

    /**
     * The policy and depth a sub-array written under {@code $normalizedKey} is
     * walked with.
     *
     * {@see self::PRESERVE_SUBTREE} is sticky forever; below the
     * immediate-children boundary everything else resumes
     * {@see self::NORMALIZE_TO_CAMEL_CASE}. An option named in
     * {@code $identifierKeyedOptions} re-opens the identifier boundary one
     * level down — its own keys are the user's words, not schema options — so
     * it answers {@see self::PRESERVE_IMMEDIATE_CHILDREN} at a depth restarted
     * to zero. `PRESERVE_SUBTREE` is already stricter and is not weakened into
     * that.
     *
     * The declared option list is passed in rather than read here: it lives in
     * {@see \Qualimetrix\Analysis\Configuration\ConfigSchema::identifierKeyedOptions()},
     * which is the loader's dependency and must not become this enum's — the
     * schema already names the enum.
     *
     * @param list<string> $identifierKeyedOptions normalized option spellings
     *
     * @return array{policy: self, depth: int}
     */
    public function descentFor(string|int $normalizedKey, array $identifierKeyedOptions, int $depth): array
    {
        if ($this === self::PRESERVE_SUBTREE) {
            return ['policy' => self::PRESERVE_SUBTREE, 'depth' => $depth + 1];
        }

        // An integer key matches nothing: every declared option is a string.
        if (\in_array($normalizedKey, $identifierKeyedOptions, true)) {
            return ['policy' => self::PRESERVE_IMMEDIATE_CHILDREN, 'depth' => 0];
        }

        return ['policy' => self::NORMALIZE_TO_CAMEL_CASE, 'depth' => $depth + 1];
    }
}
