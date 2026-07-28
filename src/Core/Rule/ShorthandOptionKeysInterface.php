<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

/**
 * Interface for rule options that accept extra shorthand configuration keys.
 *
 * {@see \Qualimetrix\Rules\Support\ThresholdParser} lets an Options class accept
 * shorthand keys — the bare `threshold`, or rule-specific ones like
 * `vo-threshold` on `code-smell.long-parameter-list` or `param-threshold` on
 * `design.type-coverage` — that are consumed inside `fromArray()` and never
 * appear as constructor parameters.
 * `Qualimetrix\Configuration\RuleOptionsFactory::warnAboutUnknownKeys()` only
 * knows about constructor parameter names via reflection, so without this
 * contract every such shorthand is falsely reported as an "Unknown option".
 *
 * Implement this interface to declare which shorthand keys `fromArray()`
 * actually accepts, in the canonical kebab-case spelling shown to users in
 * `qmx.yaml`, presets, and `--rule-opt` (see
 * `docs/internal/CLI_CONVENTIONS.md`). Options classes whose `fromArray()`
 * has no such branch — e.g. hierarchical options like `CboOptions` or
 * `InstabilityOptions`, whose top level only understands `class`/`namespace`
 * sub-configs and never routes a top-level `threshold` anywhere — must NOT
 * implement this interface: the factory then falls back to
 * constructor-parameter-only validation, which correctly keeps warning about
 * a top-level `threshold` those classes do not support.
 */
interface ShorthandOptionKeysInterface
{
    /**
     * Returns the extra shorthand keys `fromArray()` accepts beyond its
     * constructor parameter names, in canonical kebab-case (e.g.
     * `'threshold'`, `'vo-threshold'`, `'param-threshold'`).
     *
     * Declared static because this is class-level metadata, not instance
     * state — the factory consults it before any Options instance exists.
     *
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array;
}
