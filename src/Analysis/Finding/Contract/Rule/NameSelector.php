<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Stringable;

/**
 * A user-authored selector over the rule / channel name space.
 *
 * There are exactly two forms, and nothing is inferred from the number of
 * dot-separated segments:
 *
 * - `X` — **equality**. `architecture.coverage` selects the channel called
 *   `architecture.coverage` and nothing else. In particular it no longer
 *   swallows dotted descendants such as `architecture.coverage.source`: that
 *   silent capture is the defect this type exists to remove.
 * - `X.*` — **strict descendants** of `X`. `X` itself is *not* included; a
 *   directive that means both is written twice. Admitting the parent would
 *   re-blur exactly the parent/descendant boundary the equality form draws.
 *
 * Everything else is not a selector and {@see tryParse()} answers `null` for
 * it. Two cases are worth naming because they used to mean something:
 *
 * - a bare prefix (`coupling` for "every coupling rule") is no longer a guess
 *   about intent — write `coupling.*`;
 * - a lone `*` is not a selector at all. It survives only where it never
 *   selected anything in the first place: as the "no rule filter" form of the
 *   three inline suppression directives, modelled explicitly by
 *   {@see \Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget}.
 *
 * A selector that fails to parse matches nothing here. Turning that silence
 * into a loud configuration error is a separate concern with its own package;
 * this type only refuses to pretend the text selected something.
 */
final readonly class NameSelector implements Stringable
{
    private const string GROUP_SUFFIX = '.*';

    private function __construct(
        private string $name,
        private bool $descendantsOnly,
    ) {}

    /**
     * Parses selector text, or answers `null` when the text is not one of the
     * two accepted forms.
     */
    public static function tryParse(string $raw): ?self
    {
        if (str_ends_with($raw, self::GROUP_SUFFIX)) {
            $name = substr($raw, 0, -\strlen(self::GROUP_SUFFIX));

            return self::isWellFormedName($name) ? new self($name, true) : null;
        }

        return self::isWellFormedName($raw) ? new self($raw, false) : null;
    }

    /**
     * Whether this selector addresses the given rule name or finding code.
     */
    public function matches(string $subject): bool
    {
        if ($this->descendantsOnly) {
            return str_starts_with($subject, $this->name . '.');
        }

        return $subject === $this->name;
    }

    /**
     * Whether any of the raw selectors addresses the subject. Text that is not
     * a selector contributes no match.
     *
     * @param list<string> $rawSelectors
     */
    public static function anyMatch(array $rawSelectors, string $subject): bool
    {
        foreach ($rawSelectors as $raw) {
            if (self::tryParse($raw)?->matches($subject) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `X` half: the exact name for an equality selector, the parent whose
     * descendants are addressed for a group selector.
     */
    public function name(): string
    {
        return $this->name;
    }

    /** Whether this is the `X.*` form. */
    public function selectsDescendantsOnly(): bool
    {
        return $this->descendantsOnly;
    }

    public function __toString(): string
    {
        return $this->descendantsOnly ? $this->name . self::GROUP_SUFFIX : $this->name;
    }

    /**
     * A name is the literal text of one rule or channel: non-empty, free of
     * the wildcard token and of the retired channel-pair separator, and with no
     * empty dot-separated segment (which would make `a.` and `a..b` addressable
     * spellings of names no producer can ever have).
     */
    private static function isWellFormedName(string $name): bool
    {
        if ($name === '' || str_contains($name, '*')) {
            return false;
        }

        // The retired `rule#code` spelling of a channel would otherwise be a
        // well-formed name that nothing can ever carry, i.e. a selector that
        // silently addresses nothing. It is refused here so that every surface
        // validating a selector reaches its "not a selector" branch, where
        // FindingChannel::retiredPairAdvice() says what to write instead.
        if (FindingChannel::isRetiredPairSpelling($name)) {
            return false;
        }

        foreach (explode('.', $name) as $segment) {
            if ($segment === '') {
                return false;
            }
        }

        return true;
    }
}
