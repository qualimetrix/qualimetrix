<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * The one alphabet that decides whether a pattern is a glob.
 *
 * Two kinds of code ask the question and must agree: the matchers that
 * *apply* a configured value ({@see PathMatcher}, {@see NamespaceMatcher})
 * choose between `fnmatch()` and boundary-aware prefix matching by it, and the
 * code that *judges* a value reads its literal head up to the first such
 * character. When the alphabets differ, a value is applied as a literal
 * prefix on one side and cut short as a glob on the other: `{legacy}` was
 * matched literally and judged unanchored, because `{` was a glob character to
 * the judge alone.
 *
 * `{` is deliberately absent. `fnmatch()` with `FNM_NOESCAPE` performs no
 * brace expansion, so a brace is an ordinary character in a pattern the
 * matchers apply, and calling it a glob would make the judge disagree with
 * what the run actually did.
 */
final readonly class GlobSyntax
{
    /**
     * The glob characters, as a `strcspn()` mask.
     *
     * Public because a caller that needs the *position* of the first one — the
     * literal head of a value — cannot get it from a boolean.
     */
    public const string CHARACTERS = '*?[';

    public static function isGlob(string $pattern): bool
    {
        return strcspn($pattern, self::CHARACTERS) !== \strlen($pattern);
    }
}
