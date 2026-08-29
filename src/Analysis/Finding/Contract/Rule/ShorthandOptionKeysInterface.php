<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * Interface for rule options that accept extra shorthand configuration keys.
 *
 * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser} lets an Options class accept
 * shorthand keys — the bare `threshold`, or rule-specific ones like
 * `vo-threshold` on `code-smell.long-parameter-list` — that are consumed
 * inside `fromArray()` and never appear as constructor parameters.
 * `Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory::warnAboutUnknownKeys()` only
 * knows about constructor parameter names via reflection, so without this
 * contract every such shorthand is falsely reported as an "Unknown option".
 *
 * Implement this interface to declare which shorthand keys `fromArray()`
 * actually accepts, in the canonical kebab-case spelling shown to users in
 * `qmx.yaml`, presets, and `--rule-opt` (see
 * `docs/internal/CLI_CONVENTIONS.md`). Options classes whose `fromArray()`
 * has no such branch at all — e.g. `CodeSmellOptions`, which has no
 * threshold concept and never calls `ThresholdParser::parse()` — must NOT
 * implement this interface: the factory then falls back to
 * constructor-parameter-only validation, which correctly keeps warning about
 * any unrecognized key.
 *
 * This applies to hierarchical options too: `CboOptions`/`InstabilityOptions`
 * (`class`/`namespace` levels) DO implement it, because their top level ALSO
 * parses a bare `threshold` via `ThresholdParser::parse()` and applies it
 * uniformly to every nested level — see their own `fromArray()` for the
 * pattern. A hierarchical wrapper only stays unimplementing if none of its
 * levels — including its own top level — ever routes a shorthand key
 * anywhere.
 */
interface ShorthandOptionKeysInterface
{
    /**
     * Returns the extra shorthand keys `fromArray()` accepts beyond its
     * constructor parameter names, in canonical kebab-case (e.g.
     * `'threshold'`, `'vo-threshold'`).
     *
     * Declared static because this is class-level metadata, not instance
     * state — the factory consults it before any Options instance exists.
     *
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array;
}
