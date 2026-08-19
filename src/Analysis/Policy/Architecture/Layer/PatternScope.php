<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/**
 * The set of class FQNs a namespace pattern covers, in the only shape that can
 * be compared without enumerating classes: a literal namespace prefix plus a
 * flag saying whether the prefix itself is included.
 *
 * Exists so `architecture.potential-shadow` can tell a declaration defect from
 * the documented "narrow before broad" idiom. Overlap alone is not a defect —
 * first-match-wins is the declared resolution mechanism — so the diagnostic
 * must compare the two criteria that actually matched and stay silent when the
 * winning one is strictly more specific than the shadowed one.
 *
 * {@see fromCriterion()} returns `null` for every pattern whose covered set is
 * not a plain subtree ({@code App\**\Foo}, {@code **\*Service}, capture
 * templates, character classes) and for every non-pattern criterion kind
 * (suffix / attribute / implements / extends). `null` means "not comparable",
 * and the caller must then keep the diagnostic: a false alarm is cheap, a
 * missed shadow is not.
 *
 * Comparison mirrors {@see \Qualimetrix\Core\Util\NamespaceMatcher::matchesSingle()}:
 * a wildcard-free pattern matches the prefix itself and everything under it,
 * while a pattern ending in `\*` / `\**` matches only what lies under it.
 */
final readonly class PatternScope
{
    /**
     * @param bool $universal True for the catch-all `**`, which covers every FQN.
     * @param string $prefix Literal namespace prefix; empty when universal.
     * @param bool $strict True when the prefix itself is excluded (pattern ended in `\*`).
     */
    private function __construct(
        private bool $universal,
        private string $prefix,
        private bool $strict,
    ) {}

    /**
     * Returns the covered set of a pattern criterion, or `null` when the
     * criterion is not a namespace subtree and therefore not comparable.
     */
    public static function fromCriterion(MatchedCriterion $criterion): ?self
    {
        if ($criterion->kind !== MatchedCriterionKind::Pattern) {
            return null;
        }

        $pattern = rtrim($criterion->value, '\\');
        if ($pattern === '') {
            return null;
        }

        // `?` and `[` are glob syntax; `{` / `}` are unexpanded capture
        // placeholders. All three make the covered set something other than a
        // subtree.
        if (preg_match('/[?\[\]{}]/', $pattern) === 1) {
            return null;
        }

        if (trim($pattern, '*') === '') {
            return new self(true, '', false);
        }

        if (preg_match('/^(.+)\\\\\*+$/', $pattern, $matches) === 1) {
            return str_contains($matches[1], '*')
                ? null
                : new self(false, $matches[1], true);
        }

        return str_contains($pattern, '*')
            ? null
            : new self(false, $pattern, false);
    }

    /**
     * True when every FQN covered by `$other` is also covered by this scope and
     * this scope covers strictly more — i.e. `$other` is the narrower pattern.
     */
    public function strictlyContains(self $other): bool
    {
        if ($this->universal) {
            return !$other->universal;
        }

        if ($other->universal) {
            return false;
        }

        if ($this->prefix === $other->prefix) {
            return $other->strict && !$this->strict;
        }

        return str_starts_with($other->prefix, $this->prefix . '\\');
    }
}
